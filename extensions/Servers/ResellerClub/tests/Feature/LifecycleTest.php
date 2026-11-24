<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\ActionPoller;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Core\NameserverService;
use Paymenter\Extensions\Servers\ResellerClub\Core\RegistrationService;
use Paymenter\Extensions\Servers\ResellerClub\Core\RenewalService;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class LifecycleTest extends TestCase
{
    private FakeRegistrarClient $client;
    private InMemoryOperationStore $store;

    protected function setUp(): void
    {
        $this->client = new FakeRegistrarClient();
        $this->store = new InMemoryOperationStore();
    }

    /** The clock sits 34 days before the fake registrar expiry, i.e. inside the 60 day renewal window. */
    private function renewals(): RenewalService
    {
        $now = $this->client->expiry - 34 * 86400;

        return new RenewalService($this->client, $this->store, 3, 60, fn () => $now);
    }

    private function register(bool $allow = true): array
    {
        return (new RegistrationService($this->client, $this->store, $allow))
            ->register(1, 'example.com', 1, ['ns1.a.com', 'ns2.a.com'], ['email' => 'a@b.c'], ['name' => 'A']);
    }

    public function test_first_payment_registers_and_stores_order_id(): void
    {
        $r = $this->register();
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(555, $r['order_id']);
        $this->assertSame(555, $this->store->find('register:1')['rc_order_id']);
    }

    public function test_repeat_registration_makes_no_second_call(): void
    {
        $this->register();
        $this->register();
        $this->assertSame(1, $this->client->count('register'));
    }

    public function test_taken_domain_is_a_failed_operation(): void
    {
        $this->client->registerError = new RegistrarException('Domain already registered', RegistrarException::TAKEN);
        $r = $this->register();
        $this->assertSame('failed', $r['status']);
        $this->assertSame('failed', $this->store->find('register:1')['status']);
        $this->assertNull($r['order_id']);
    }

    public function test_registrations_disabled_fails_without_registrar_call(): void
    {
        $r = $this->register(false);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('disabled', $r['error']);
        $this->assertSame([], $this->client->calls);
    }

    public function test_renewal_renews_once(): void
    {
        $svc = $this->renewals();
        $target = $this->client->expiry + 365 * 86400;
        $key = $svc->record(1, $target, 1);
        $svc->run($key, 555);
        $this->assertSame(1, $this->client->count('renew'));
        $this->assertSame('submitted', $this->store->find($key)['status']);
    }

    public function test_duplicate_event_creates_one_operation(): void
    {
        $svc = $this->renewals();
        $target = $this->client->expiry + 365 * 86400;
        $this->assertNotNull($svc->record(1, $target, 1));
        $this->assertNull($svc->record(1, $target, 1));
    }

    public function test_refund_rerenewal_with_registrar_already_at_target_makes_no_call(): void
    {
        $svc = $this->renewals();
        $target = $this->client->expiry + 365 * 86400;
        $svc->run($svc->record(1, $target, 1), 555);
        $this->assertSame(1, $this->client->count('renew'));

        // A duplicate event whose date is a bit different: the registrar is already there.
        $key2 = $svc->record(1, $target + 86400, 1);
        $svc->run($key2, 555);
        $this->assertSame(1, $this->client->count('renew'));
        $this->assertSame('completed', $this->store->find($key2)['status']);
    }

    public function test_issue_1416_refund_moves_service_a_full_term_ahead_and_is_not_renewed(): void
    {
        $svc = $this->renewals();
        $target = $this->client->expiry + 365 * 86400;
        $svc->run($svc->record(1, $target, 1), 555);
        $this->assertSame(1, $this->client->count('renew'));

        // Refunding the cancelled paid invoice re-renews the service one whole term further.
        $key2 = $svc->record(1, $target + 365 * 86400, 1);
        $svc->run($key2, 555);
        $this->assertSame(1, $this->client->count('renew'));
        $this->assertSame('failed', $this->store->find($key2)['status']);
        $this->assertStringContainsString('days away', $this->store->find($key2)['last_error']);
    }

    public function test_missing_registrar_expiry_never_renews(): void
    {
        $svc = $this->renewals();
        $this->client->expiry = 0;
        $key = $svc->record(1, 1_900_000_000, 1);
        $svc->run($key, 555);
        $this->assertSame(0, $this->client->count('renew'));
        $this->assertSame('failed', $this->store->find($key)['status']);
    }

    public function test_timeout_leaves_operation_retryable_and_rechecks_live_expiry(): void
    {
        $svc = $this->renewals();
        $key = $svc->record(1, $this->client->expiry + 365 * 86400, 1);
        $this->client->renewError = new RegistrarException('timeout', RegistrarException::TIMEOUT);
        try {
            $svc->run($key, 555);
            $this->fail('expected exception');
        } catch (RegistrarException) {
        }
        $this->assertSame('queued', $this->store->find($key)['status']);

        // The write actually landed: registrar expiry is now at target.
        $this->client->renewError = null;
        $this->client->expiry += 365 * 86400;
        $svc->run($key, 555);
        $this->assertSame(1, $this->client->count('renew')); // only the timed-out attempt; the retry made no call
        $this->assertSame('completed', $this->store->find($key)['status']);
    }

    public function test_funds_failure_marks_failed_and_can_be_retried(): void
    {
        $svc = $this->renewals();
        $key = $svc->record(1, $this->client->expiry + 365 * 86400, 1);
        $this->client->renewError = new RegistrarException('Insufficient funds', RegistrarException::FUNDS);
        $svc->run($key, 555);
        $this->assertSame('failed', $this->store->find($key)['status']);

        $this->client->renewError = null;
        $svc->run($key, 555);
        $this->assertSame('submitted', $this->store->find($key)['status']);
    }

    public function test_nameserver_change_then_poll_completes(): void
    {
        $ns = new NameserverService($this->client, $this->store);
        $key = $ns->change(1, 555, ['NS1.example.org', 'ns2.example.org']);
        $this->assertSame(0, $this->client->count('modifyNameservers')); // recording is not writing
        $ns->run($key, 555);
        $this->assertSame('submitted', $this->store->find($key)['status']);
        $this->assertSame('completed', (new ActionPoller($this->client, $this->store))->poll($key));
    }

    public function test_registration_off_still_allows_renewal_and_nameservers(): void
    {
        $this->register(false);
        $svc = $this->renewals();
        $svc->run($svc->record(1, $this->client->expiry + 365 * 86400, 1), 555);
        $ns = new NameserverService($this->client, $this->store);
        $ns->run($ns->change(1, 555, ['ns1.example.org', 'ns2.example.org']), 555);
        $this->assertSame(1, $this->client->count('renew'));
        $this->assertSame(1, $this->client->count('modifyNameservers'));
    }

    public function test_timed_out_registration_looks_up_order_before_retry(): void
    {
        $this->client->registerError = new RegistrarException('timeout', RegistrarException::TIMEOUT);
        try {
            $this->register();
            $this->fail('expected exception');
        } catch (RegistrarException) {
        }
        $this->client->registerError = null;
        $this->client->existingOrders['example.com'] = 777;
        $r = $this->register();
        $this->assertSame(777, $r['order_id']);
        $this->assertSame(1, $this->client->count('register'));
    }

    public function test_registration_works_when_a_new_row_lacks_the_database_default_columns(): void
    {
        // The real database store returned a just-created row without attempts/rc_order_id/... (their defaults live in
        // the database), which crashed the first registration in production: "Undefined array key attempts".
        $this->store = new class extends InMemoryOperationStore {
            public function firstOrCreate(int $serviceId, string $type, string $key, array $payload = []): array
            {
                [$row, $created] = parent::firstOrCreate($serviceId, $type, $key, $payload);
                if ($created) {
                    $row = array_diff_key($row, array_flip(['attempts', 'rc_order_id', 'rc_action_id', 'last_error']));
                }

                return [$row, $created];
            }
        };
        $r = $this->register();
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(555, $r['order_id']);
        $this->assertSame(1, $this->store->find(RegistrationService::key(1))['attempts']);
    }

    private function transfer(bool $allow = true, string $code = 'EPP-123'): array
    {
        return (new RegistrationService($this->client, $this->store, $allow))
            ->register(2, 'mine.co.uk', 1, [], ['email' => 'a@b.c'], ['name' => 'A'], ['transfer' => true, 'auth_code' => $code]);
    }

    public function test_transfer_calls_transfer_not_register_and_records_a_transfer_operation(): void
    {
        $r = $this->transfer();
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(556, $r['order_id']);
        $this->assertSame(1, $this->client->count('transfer'));
        $this->assertSame(0, $this->client->count('register'));
        $this->assertSame('EPP-123', $this->client->lastAuthCode);
        $this->assertSame('transfer', $this->store->find(RegistrationService::key(2))['type']);
    }

    public function test_repeat_transfer_makes_no_second_call(): void
    {
        $this->transfer();
        $this->transfer();
        $this->assertSame(1, $this->client->count('transfer'));
    }

    public function test_transfer_respects_registrations_switch(): void
    {
        $r = $this->transfer(false);
        $this->assertSame('failed', $r['status']);
        $this->assertSame(0, $this->client->count('transfer'));
    }

    public function test_rejected_transfer_is_failed_and_retryable(): void
    {
        $this->client->registerError = new RegistrarException('Invalid auth code', RegistrarException::API);
        $r = $this->transfer();
        $this->assertSame('failed', $r['status']);
        $op = $this->store->find(RegistrationService::key(2));
        $this->assertTrue((new \Paymenter\Extensions\Servers\ResellerClub\Core\OperationRetry($this->store))->canRetry($op));
    }
}
