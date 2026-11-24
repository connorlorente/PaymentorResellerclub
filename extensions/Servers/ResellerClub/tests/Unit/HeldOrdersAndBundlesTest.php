<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\CartBundle;
use Paymenter\Extensions\Servers\ResellerClub\Core\EmailTemplates;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Core\RegistrationWatcher;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldRules;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderDetails;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class HeldOrdersAndBundlesTest extends TestCase
{
    private function watcher(FakeRegistrarClient $client, InMemoryOperationStore $store, array &$events): RegistrationWatcher
    {
        return new RegistrationWatcher(
            $client, $store,
            function (array $row) use (&$events) { $events[] = 'held'; },
            function (array $row, int $expires) use (&$events) { $events[] = "active:$expires"; },
        );
    }

    private function op(InMemoryOperationStore $store, string $type = 'register'): string
    {
        $store->firstOrCreate(7, $type, 'register:7', ['domain' => 'shop.co.uk']);
        $store->update('register:7', ['status' => 'submitted', 'rc_order_id' => 555]);

        return 'register:7';
    }

    public function test_an_order_held_for_funds_waits_instead_of_failing_and_tells_the_admin_once(): void
    {
        $client = new FakeRegistrarClient();
        $client->orderStatus = 'inactive';
        $client->fundsShort = true;
        $client->expiry = 0;
        $store = new InMemoryOperationStore();
        $events = [];
        $w = $this->watcher($client, $store, $events);
        $key = $this->op($store);

        $this->assertSame('awaiting_funds', $w->check($key));
        $this->assertSame('awaiting_funds', $w->check($key)); // five minutes later, still held
        $this->assertSame(['held'], $events); // one email, not one every poll
        $this->assertStringContainsString('Waiting for ResellerClub funds', $store->find($key)['last_error']);
    }

    public function test_funding_the_account_completes_the_held_order_and_activates_it(): void
    {
        $client = new FakeRegistrarClient();
        $client->orderStatus = 'inactive';
        $client->fundsShort = true;
        $client->expiry = 0;
        $store = new InMemoryOperationStore();
        $events = [];
        $w = $this->watcher($client, $store, $events);
        $key = $this->op($store);
        $w->check($key);

        $client->orderStatus = 'active';
        $client->fundsShort = false;
        $client->expiry = 1_900_000_000;
        $this->assertSame('completed', $w->check($key));
        $this->assertSame(['held', 'active:1900000000'], $events);
    }

    public function test_an_order_that_is_live_straight_away_completes_without_a_funds_alert(): void
    {
        $client = new FakeRegistrarClient();
        $store = new InMemoryOperationStore();
        $events = [];
        $key = $this->op($store);
        $this->assertSame('completed', $this->watcher($client, $store, $events)->check($key));
        $this->assertSame(['active:1800000000'], $events);
    }

    public function test_a_registrar_outage_leaves_the_state_alone(): void
    {
        $client = new class extends FakeRegistrarClient {
            public function orderDetails(int $orderId): OrderDetails
            {
                throw new RegistrarException('down', RegistrarException::UNREACHABLE);
            }
        };
        $store = new InMemoryOperationStore();
        $events = [];
        $key = $this->op($store);
        $this->assertSame('submitted', $this->watcher($client, $store, $events)->check($key));
        $this->assertSame([], $events);
    }

    public function test_only_registrations_and_transfers_are_watched(): void
    {
        $client = new FakeRegistrarClient();
        $store = new InMemoryOperationStore();
        $events = [];
        $store->firstOrCreate(7, 'renew', 'renew:7:1', []);
        $store->update('renew:7:1', ['status' => 'submitted', 'rc_order_id' => 555]);
        $this->assertSame('submitted', $this->watcher($client, $store, $events)->check('renew:7:1'));
        $this->assertSame([], $events);
    }

    public function test_the_funds_reason_is_found_wherever_the_registrar_puts_it(): void
    {
        $c = new LegacyHttpClient('1', 'KEY', false, fn () => ['status' => 200, 'body' => json_encode([
            'currentstatus' => 'InActive', 'orderstatus' => ['x'], 'description' => 'Order failed: Reseller has insufficient funds', 'ns1' => 'a.example.com',
        ])]);
        $d = $c->orderDetails(1);
        $this->assertTrue($d->fundsShort);
        $this->assertSame('inactive', $d->status);
        $this->assertFalse($d->isActive());

        $ok = new LegacyHttpClient('1', 'KEY', false, fn () => ['status' => 200, 'body' => json_encode(['currentstatus' => 'Active', 'endtime' => '1900000000'])]);
        $this->assertTrue($ok->orderDetails(1)->isActive());
        $this->assertFalse($ok->orderDetails(1)->fundsShort);
    }

    public function test_privacy_fields_are_read_when_present(): void
    {
        $c = new LegacyHttpClient('1', 'KEY', false, fn () => ['status' => 200, 'body' => json_encode(['privacyprotectedallowed' => 'true', 'privacyprotected' => 'false'])]);
        $d = $c->orderDetails(1);
        $this->assertTrue($d->privacyAllowed);
        $this->assertFalse($d->privacyProtected);
        $this->assertNull((new LegacyHttpClient('1', 'KEY', false, fn () => ['status' => 200, 'body' => '{}']))->orderDetails(1)->privacyAllowed);
    }

    public function test_privacy_change_is_a_post_with_the_order_id(): void
    {
        $seen = null;
        $c = new LegacyHttpClient('1', 'KEY', false, function ($m, $u, $p) use (&$seen) { $seen = [$m, $u, $p]; return ['status' => 200, 'body' => '{"status":"Success"}']; });
        $c->setPrivacy(42, true);
        $this->assertSame('POST', $seen[0]);
        $this->assertStringEndsWith('domains/modify-privacy-protection.json', $seen[1]);
        $this->assertSame('true', $seen[2]['protect-privacy']);
    }

    public function test_privacy_is_only_for_extensions_that_offer_it(): void
    {
        foreach (['shop.com', 'shop.net', 'shop.org', 'shop.info', 'shop.biz'] as $d) {
            $this->assertTrue(TldRules::supportsPrivacy($d), $d);
        }
        foreach (['shop.co.uk', 'shop.uk', 'shop.org.uk', 'shop.eu', 'shop.us'] as $d) {
            $this->assertFalse(TldRules::supportsPrivacy($d), $d);
        }
        $this->assertTrue(TldRules::truthy('1') && TldRules::truthy('Yes') && TldRules::truthy(true));
        $this->assertFalse(TldRules::truthy('') || TldRules::truthy('0') || TldRules::truthy('no') || TldRules::truthy(null) || TldRules::truthy(false));
    }

    public function test_the_order_email_template_gets_the_domain_once_and_only_when_stock(): void
    {
        $stock = "| {{ \$item->product->name }} | {{ \$item->quantity }} |";
        $once = EmailTemplates::withDomain($stock);
        $this->assertStringContainsString("firstWhere('key', 'domain')?->value", $once);
        $this->assertSame($once, EmailTemplates::withDomain($once)); // idempotent
        $this->assertSame('custom body', EmailTemplates::withDomain('custom body')); // someone else's template is left alone
    }

    public function test_cart_pair_stays_in_step(): void
    {
        $bundled = [10 => ['domain' => 'shop.co.uk', 'action' => 'register', 'auth' => '']];

        // unchanged choice: keep it, add nothing
        $this->assertSame(['delete' => [], 'keep' => 10, 'create' => false, 'auth' => null], CartBundle::decide('register', 'shop.co.uk', $bundled));
        // a different name replaces it
        $this->assertSame(['delete' => [10], 'keep' => null, 'create' => true, 'auth' => null], CartBundle::decide('register', 'other.co.uk', $bundled));
        // switching to a transfer replaces it
        $this->assertSame(['delete' => [10], 'keep' => null, 'create' => true, 'auth' => null], CartBundle::decide('transfer', 'shop.co.uk', $bundled, 'CODE'));
        // choosing "own domain" removes it
        $this->assertSame(['delete' => [10], 'keep' => null, 'create' => false, 'auth' => null], CartBundle::decide('own', 'shop.co.uk', $bundled));
        // a name that is not for sale removes it too
        $this->assertSame(['delete' => [10], 'keep' => null, 'create' => false, 'auth' => null], CartBundle::decide('register', null, $bundled));
        // nothing bundled yet: create
        $this->assertSame(['delete' => [], 'keep' => null, 'create' => true, 'auth' => null], CartBundle::decide('register', 'shop.co.uk', []));
        // a corrected transfer code is passed on to the kept item
        $t = [11 => ['domain' => 'shop.co.uk', 'action' => 'transfer', 'auth' => 'OLD']];
        $this->assertSame('NEW', CartBundle::decide('transfer', 'shop.co.uk', $t, 'NEW')['auth']);
        $this->assertNull(CartBundle::decide('transfer', 'shop.co.uk', $t, 'OLD')['auth']);
        // duplicates are collapsed to one
        $dup = [1 => ['domain' => 'a.com', 'action' => 'register', 'auth' => ''], 2 => ['domain' => 'a.com', 'action' => 'register', 'auth' => '']];
        $this->assertSame([2], CartBundle::decide('register', 'a.com', $dup)['delete']);
    }
}
