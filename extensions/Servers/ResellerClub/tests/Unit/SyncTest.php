<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\CostTable;
use Paymenter\Extensions\Servers\ResellerClub\Core\ExpirySync;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Core\PriceSync;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderSummary;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class SyncTest extends TestCase
{
    private const DAY = 86400;

    public function test_classify_follows_the_reconciliation_rules(): void
    {
        $e = 1_800_000_000;
        $this->assertSame(ExpirySync::IN_SYNC, ExpirySync::classify($e + 2 * self::DAY, $e, 3, false));
        $this->assertSame(ExpirySync::REGISTRAR_LATER, ExpirySync::classify($e + 400 * self::DAY, $e, 3, true));
        $this->assertSame(ExpirySync::REGISTRAR_EARLIER, ExpirySync::classify($e - 30 * self::DAY, $e, 3, false));
        $this->assertSame(ExpirySync::RENEWAL_FAILED, ExpirySync::classify($e - 30 * self::DAY, $e, 3, true));
    }

    public function test_sync_pages_saves_expiry_and_fails_a_silent_renewal(): void
    {
        $client = new FakeRegistrarClient();
        for ($i = 1; $i <= 5; $i++) {
            $client->orders[] = new OrderSummary($i, "d$i.com", 1_800_000_000);
        }
        $store = new InMemoryOperationStore();
        $store->firstOrCreate(3, 'renew', 'renew:3:x');
        $store->update('renew:3:x', ['status' => 'completed']);

        $local = [
            1 => ['service_id' => 10, 'expires_at' => 1_800_000_000, 'renew_key' => null],
            3 => ['service_id' => 30, 'expires_at' => 1_800_000_000 + 365 * self::DAY, 'renew_key' => 'renew:3:x'],
            5 => ['service_id' => 50, 'expires_at' => 1_800_000_000 - 100 * self::DAY, 'renew_key' => null], // registrar later
        ];
        $saved = [];
        $report = (new ExpirySync($client, $store, 3, 2))->run($local, function ($sid, $exp) use (&$saved) {
            $saved[$sid] = $exp;
        });

        $this->assertSame(3, $client->count('searchOrders')); // 5 orders, 2 per page
        $this->assertSame([10 => 1_800_000_000, 30 => 1_800_000_000, 50 => 1_800_000_000], $saved);
        $this->assertSame('failed', $store->find('renew:3:x')['status']);
        $this->assertSame([ExpirySync::RENEWAL_FAILED, ExpirySync::REGISTRAR_LATER], array_column($report, 'outcome'));
    }

    public function test_sync_keeps_paging_when_the_response_has_no_usable_total(): void
    {
        $client = new class extends FakeRegistrarClient {
            public function searchOrders(int $page, int $perPage = 100): \Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderPage
            {
                $this->calls[] = __FUNCTION__;
                $rows = array_slice($this->orders, ($page - 1) * $perPage, $perPage);

                return new \Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderPage($rows, count($rows)); // total = rows on this page
            }
        };
        foreach (range(1, 5) as $i) {
            $client->orders[] = new OrderSummary($i, "d$i.com", 1_800_000_000);
        }
        $local = [5 => ['service_id' => 50, 'expires_at' => 1_800_000_000, 'renew_key' => null]];
        $saved = [];
        (new ExpirySync($client, new InMemoryOperationStore(), 3, 2))->run($local, function ($sid) use (&$saved) {
            $saved[] = $sid;
        });
        $this->assertSame([50], $saved); // order 5 sits on page 3
    }

    public function test_cost_table_parses_known_shape_and_skips_the_rest(): void
    {
        $raw = ['dotcom' => ['addnewdomain' => ['1' => '8.50', '2' => '17']], 'sunrisedonutsgroup1' => ['addnewdomain' => ['1' => '9']],
            'dotio' => ['tld' => 'io', 'addnewdomain' => ['1' => '0']], 'junk' => 'x'];
        $this->assertSame(['com' => [1 => 8.5, 2 => 17.0]], CostTable::parse($raw));
    }

    public function test_product_markup_overrides_server_default_only_when_set(): void
    {
        $server = ['markup_type' => 'percent', 'markup_value' => '20'];
        $this->assertSame(['percent', 20.0], PriceSync::markupFor($server, ['markup_value' => '']));
        $this->assertSame(['fixed', 3.0], PriceSync::markupFor($server, ['markup_type' => 'fixed', 'markup_value' => '3']));
    }

    public function test_propose_returns_only_changes_and_missing_prices(): void
    {
        $costs = ['com' => [1 => 10.0, 2 => 20.0]];
        $m = ['percent', 10.0];
        $plans = [
            ['plan_id' => 1, 'tld' => 'com', 'years' => 1, 'current' => 11.0, 'markup' => $m], // unchanged
            ['plan_id' => 2, 'tld' => 'com', 'years' => 2, 'current' => 20.0, 'markup' => $m], // 22.00 proposed
            ['plan_id' => 3, 'tld' => 'com', 'years' => 3, 'current' => null, 'markup' => $m], // no registrar term
            ['plan_id' => 4, 'tld' => 'com', 'years' => 1, 'current' => null, 'markup' => $m], // no price yet
        ];
        $out = PriceSync::propose($costs, $plans);
        $this->assertSame([2, 4], array_column($out, 'plan_id'));
        $this->assertSame(22.0, $out[0]['proposed']);
    }

    public function test_search_orders_parses_rows_and_skips_counters(): void
    {
        $body = '{"recsonpage":"1","recsindb":"7","1":{"orders.orderid":"55","entity.description":"Example.COM","orders.endtime":"1800000000"}}';
        $c = new LegacyHttpClient('1', 'k', false, fn () => ['status' => 200, 'body' => $body]);
        $page = $c->searchOrders(1, 1);
        $this->assertSame(7, $page->total);
        $this->assertSame([55, 'example.com', 1800000000], [$page->orders[0]->orderId, $page->orders[0]->domain, $page->orders[0]->expiresAt]);
    }
}
