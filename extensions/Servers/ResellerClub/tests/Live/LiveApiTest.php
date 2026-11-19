<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Live;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\CostTable;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegisterRequest;

/**
 * Real calls against the ResellerClub TEST host (test.httpapi.com) with a demo account.
 *
 *   RC_ID=<reseller id> RC_KEY=<api key> php vendor/bin/phpunit --testsuite Live
 *
 * Skipped when RC_ID/RC_KEY are unset. The server's public IP must be whitelisted at Settings > API.
 * Tests that create customers, contacts or orders also need RC_WRITE=1. RC_RECORD=<dir> saves each raw
 * response (api key removed) so it can become a contract-test fixture.
 */
class LiveApiTest extends TestCase
{
    private LegacyHttpClient $client;
    private string $record = '';
    private static array $state = [];

    protected function setUp(): void
    {
        $id = getenv('RC_ID');
        $key = getenv('RC_KEY');
        if (!$id || !$key) {
            $this->markTestSkipped('Set RC_ID and RC_KEY to run the live tests.');
        }
        $this->record = (string) getenv('RC_RECORD');

        $client = new LegacyHttpClient($id, $key, false);
        $curl = new \ReflectionMethod($client, 'curl');
        $n = 0;
        $transport = function (string $method, string $url, array $params) use ($client, $curl, $key, &$n) {
            $resp = $curl->invoke($client, $method, $url, $params);
            if ($this->record !== '') {
                @mkdir($this->record, 0777, true);
                $name = preg_replace('/[^a-z0-9]+/i', '_', parse_url($url, PHP_URL_PATH));
                file_put_contents($this->record . '/' . $this->name() . '_' . (++$n) . $name . '.txt',
                    str_replace($key, '[KEY]', "$method $url\nHTTP {$resp['status']}\n{$resp['body']}"));
            }

            return $resp;
        };
        $this->client = new LegacyHttpClient($id, $key, false, $transport);
    }

    private function needsWrite(): void
    {
        if (getenv('RC_WRITE') !== '1') {
            $this->markTestSkipped('Set RC_WRITE=1 to run tests that create demo-account data.');
        }
    }

    public function test_credentials_and_ip_are_accepted(): void
    {
        $this->client->ping();
        $this->addToAssertionCount(1);
    }

    public function test_availability_uses_documented_statuses(): void
    {
        $label = 'rcprobe' . random_int(100000, 999999);
        $out = $this->client->checkAvailability([$label], ['com', 'net']);
        $this->assertArrayHasKey("$label.com", $out);
        foreach ($out as $status) {
            $this->assertContains($status, ['available', 'regthroughus', 'regthroughothers', 'unknown', 'premium']);
        }
    }

    public function test_order_search_pages_and_exposes_a_total(): void
    {
        $page = $this->client->searchOrders(1, 10);
        $this->assertIsArray($page->orders);
        $this->assertIsInt($page->total);
        foreach ($page->orders as $o) {
            $this->assertGreaterThan(0, $o->orderId);
            $this->assertNotSame('', $o->domain);
            // orders.endtime only exists once the order is active (an unfunded demo order has none).
            $this->assertTrue($o->expiresAt === 0 || $o->expiresAt > 1_000_000_000, 'orders.endtime should be epoch seconds');
        }
    }

    public function test_unknown_domain_has_no_order_id(): void
    {
        $this->assertNull($this->client->orderIdForDomain('surely-unregistered-' . random_int(100000, 999999) . '.com'));
    }

    public function test_unknown_action_id_reads_as_unknown_not_an_error(): void
    {
        $this->assertSame('unknown', $this->client->actionStatus(999999999)->status);
    }

    public function test_cost_prices_parse_into_a_tld_table(): void
    {
        $costs = CostTable::parse($this->client->resellerCostPrices());
        $this->assertNotEmpty($costs, 'CostTable::parse found no TLD costs: the response shape differs from the assumption.');
        $this->assertArrayHasKey('com', $costs);
        $this->assertArrayHasKey(1, $costs['com']);
    }

    public function test_write_01_customer_and_contact(): void
    {
        $this->needsWrite();
        $email = 'rc-probe-' . random_int(100000, 999999) . '@example.com';
        $person = ['email' => $email, 'name' => 'Probe Tester', 'company' => 'N/A', 'address' => '1 Test Street', 'city' => 'London',
            'state' => 'London', 'country' => 'GB', 'zip' => 'SW1A 1AA', 'phone_cc' => '44', 'phone' => '2071234567'];
        self::$state['customer'] = $this->client->ensureCustomer($person);
        $this->assertGreaterThan(0, self::$state['customer']);
        $this->assertSame(self::$state['customer'], $this->client->ensureCustomer($person), 'a second lookup should find the same customer');
        self::$state['contact'] = $this->client->createContact($person, self::$state['customer']);
        $this->assertGreaterThan(0, self::$state['contact']);
    }

    public function test_write_02_register_then_read_back(): void
    {
        $this->needsWrite();
        $this->assertArrayHasKey('contact', self::$state, 'needs test_write_01');
        self::$state['domain'] = 'rcprobe' . random_int(100000, 999999) . '.com';
        $res = $this->client->register(new RegisterRequest(self::$state['domain'], 1, ['ns1.domain.com', 'ns2.domain.com'], self::$state['customer'], self::$state['contact']));
        $this->assertGreaterThan(0, $res->orderId);
        $this->assertGreaterThan(0, $res->actionId);
        self::$state['order'] = $res->orderId;
        self::$state['action'] = $res->actionId;

        $this->assertSame(self::$state['order'], $this->client->orderIdForDomain(self::$state['domain']));
        $details = $this->client->orderDetails(self::$state['order']);
        $this->assertSame(['ns1.domain.com', 'ns2.domain.com'], array_values(array_intersect(['ns1.domain.com', 'ns2.domain.com'], $details->nameservers)));
        // endtime is absent until the order is active; the reseller needs funds for that.
        $this->assertTrue($details->expiresAt === 0 || $details->expiresAt > 1_000_000_000, 'domains/details.json endtime should be epoch seconds');
        self::$state['expiry'] = $details->expiresAt;
    }

    public function test_write_03_action_status_resolves(): void
    {
        $this->needsWrite();
        $this->assertArrayHasKey('action', self::$state, 'needs test_write_02');
        $status = $this->client->actionStatus(self::$state['action']);
        fwrite(STDERR, "\n[live] register action status: {$status->status} / {$status->description}\n");
        $this->assertNotSame('unknown', $status->status, 'the actions search did not return the row for this eaqid');
    }

    public function test_write_04_renew_and_nameservers(): void
    {
        $this->needsWrite();
        $this->assertArrayHasKey('expiry', self::$state, 'needs test_write_02');
        if (self::$state['expiry'] === 0) {
            // Unfunded demo reseller: the order stays InActive and the registrar refuses both writes with plain API errors.
            foreach (['renew' => fn () => $this->client->renew(self::$state['order'], 1, 0, false),
                'nameservers' => fn () => $this->client->modifyNameservers(self::$state['order'], ['ns1.domain.com', 'ns2.domain.com'])] as $what => $call) {
                try {
                    $call();
                    $this->fail("$what on an inactive order should be refused");
                } catch (\Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException $e) {
                    $this->assertSame('api', $e->kind, "$what: {$e->getMessage()}");
                }
            }
            $this->markTestIncomplete('Order is inactive (demo reseller has no funds): add funds in the demo panel to exercise a real renew and nameserver change.');
        }
        $renew = $this->client->renew(self::$state['order'], 1, self::$state['expiry'], false);
        $this->assertGreaterThan(0, $renew->actionId);

        $ns = $this->client->modifyNameservers(self::$state['order'], ['ns1.example.org', 'ns2.example.org']);
        $this->assertGreaterThan(0, $ns->actionId);
        foreach ([$renew->actionId, $ns->actionId] as $eaq) {
            fwrite(STDERR, "[live] action $eaq: " . $this->client->actionStatus($eaq)->status . "\n");
        }
    }
}
