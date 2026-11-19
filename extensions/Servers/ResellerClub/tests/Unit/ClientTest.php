<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\Markup;
use Paymenter\Extensions\Servers\ResellerClub\Core\NameserverService;
use Paymenter\Extensions\Servers\ResellerClub\Core\Redactor;
use Paymenter\Extensions\Servers\ResellerClub\Core\RenewalService;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegisterRequest;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

class ClientTest extends TestCase
{
    private function client(array $response, ?array &$seen = null): LegacyHttpClient
    {
        return new LegacyHttpClient('123', 'SECRETKEY', false, function ($m, $url, $p) use ($response, &$seen) {
            $seen = ['method' => $m, 'url' => $url, 'params' => $p];

            return $response;
        });
    }

    public function test_renew_sends_registrar_expiry_and_credentials(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"eaqid":42,"entityid":9,"actionstatus":"Success"}'], $seen);
        $r = $c->renew(9, 2, 1800000000, false);
        $this->assertSame('https://test.httpapi.com/api/domains/renew.json', $seen['url']);
        $this->assertSame(1800000000, $seen['params']['exp-date']);
        $this->assertSame('123', $seen['params']['auth-userid']);
        $this->assertSame(42, $r->actionId);
    }

    public function test_availability_uses_separate_host_and_flags_premium(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"a.com":{"status":"available"},"b.io":{"status":"available","costHash":"x"}}'], $seen);
        $out = $c->checkAvailability(['a'], ['com']);
        $this->assertStringStartsWith('https://domaincheck.httpapi.com/', $seen['url']);
        $this->assertSame('premium', $out['b.io']);
    }

    public function test_error_status_throws_typed_exception_without_leaking_key(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"status":"ERROR","message":"Insufficient funds SECRETKEY"}']);
        try {
            $c->renew(1, 1, 1, false);
            $this->fail();
        } catch (RegistrarException $e) {
            $this->assertSame(RegistrarException::FUNDS, $e->kind);
            $this->assertStringNotContainsString('SECRETKEY', $e->getMessage());
        }
    }

    public function test_bad_key_and_ip_failures_are_distinct(): void
    {
        $bad = $this->client(['status' => 200, 'body' => '{"status":"ERROR","message":"Invalid credentials"}']);
        $ip = $this->client(['status' => 403, 'body' => 'Forbidden']);
        $kinds = [];
        foreach ([$bad, $ip] as $c) {
            try {
                $c->ping();
            } catch (RegistrarException $e) {
                $kinds[] = $e->kind;
            }
        }
        $this->assertSame([RegistrarException::AUTH, RegistrarException::IP_NOT_WHITELISTED], $kinds);
    }

    public function test_documented_ip_denial_message_is_reported_as_ip_failure(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"status":"ERROR","message":"Access Denied. You are not allowed to perform this action."}']);
        try {
            $c->ping();
            $this->fail('expected an exception');
        } catch (RegistrarException $e) {
            $this->assertSame(RegistrarException::IP_NOT_WHITELISTED, $e->kind);
        }
    }

    public function test_ping_respects_documented_minimum_page_size(): void
    {
        $this->client(['status' => 200, 'body' => '{}'], $seen)->ping();
        $this->assertGreaterThanOrEqual(10, $seen['params']['no-of-records']);
    }

    public function test_uk_and_ca_registrations_send_minus_one_contacts_and_no_privacy_flag(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"entityid":5,"eaqid":6}'], $seen);
        $c->register(new RegisterRequest('shop.co.uk', 1, ['ns1.a.com', 'ns2.a.com'], 11, 22));
        $p = $seen['params'];
        $this->assertSame([22, -1, -1, -1], [$p['reg-contact-id'], $p['admin-contact-id'], $p['tech-contact-id'], $p['billing-contact-id']]);
        $this->assertArrayNotHasKey('purchase-privacy', $p);

        $c->register(new RegisterRequest('shop.ca', 1, ['ns1.a.com', 'ns2.a.com'], 11, 22, true));
        $p = $seen['params'];
        $this->assertSame([22, 22, -1], [$p['admin-contact-id'], $p['tech-contact-id'], $p['billing-contact-id']]);
        $this->assertSame('true', $p['purchase-privacy']);

        $c->register(new RegisterRequest('shop.com', 1, ['ns1.a.com', 'ns2.a.com'], 11, 22));
        $this->assertSame(22, $seen['params']['billing-contact-id']);
    }

    public function test_action_status_finds_the_row_by_eaqid_whatever_its_key(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"recsonpage":"1","7":{"eaqid":"4242","actionstatus":"Success","actionstatusdesc":"ok"}}']);
        $this->assertTrue($c->actionStatus(4242)->isCompleted());
        $this->assertSame('unknown', $c->actionStatus(1)->status);
    }

    public function test_lowercase_error_status_from_register_throws(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"status":"error","error":"Error occurred while retrieving host check response"}']);
        $this->expectException(RegistrarException::class);
        $this->expectExceptionMessage('host check');
        $c->register(new RegisterRequest('a.com', 1, ['ns1.a.com', 'ns2.a.com'], 1, 2));
    }

    public function test_action_status_with_no_record_is_unknown_not_an_exception(): void
    {
        $c = $this->client(['status' => 500, 'body' => '{"status":"ERROR","message":"No record found"}']);
        $this->assertSame('unknown', $c->actionStatus(5)->status);
    }

    public function test_cost_table_maps_legacy_product_keys(): void
    {
        $row = ['addnewdomain' => ['1' => '12.49'], 'renewdomain' => ['1' => '12.49']];
        $costs = \Paymenter\Extensions\Servers\ResellerClub\Core\CostTable::parse(['domcno' => $row, 'dotnet' => $row, 'thirdleveldotuk' => $row, 'sunrisedonutsgroup1' => $row, 'businesshostinglinuxin' => ['plans' => []]]);
        $this->assertSame(['com', 'net', 'co.uk'], array_keys($costs));
        $this->assertSame(12.49, $costs['com'][1]);
    }

    public function test_customer_lookup_only_treats_plain_api_errors_as_unknown_customer(): void
    {
        $calls = [];
        $c = new LegacyHttpClient('123', 'SECRETKEY', false, function ($m, $url) use (&$calls) {
            $calls[] = $url;

            return str_contains($url, 'details') ? ['status' => 200, 'body' => '{"status":"ERROR","message":"No such customer"}'] : ['status' => 200, 'body' => '77'];
        });
        $this->assertSame(77, $c->ensureCustomer(['email' => 'a@b.c', 'name' => 'A', 'address' => 'x', 'city' => 'c', 'country' => 'GB', 'zip' => '1', 'phone_cc' => '44', 'phone' => '123456']));

        $down = new LegacyHttpClient('123', 'SECRETKEY', false, fn () => ['status' => 200, 'body' => '{"status":"ERROR","message":"Insufficient funds"}']);
        $this->expectException(RegistrarException::class);
        $down->ensureCustomer(['email' => 'a@b.c']);
    }

    public function test_idempotency_key_format(): void
    {
        $this->assertSame('renew:7:1800000000', RenewalService::key(7, 1800000000));
    }

    public function test_markup(): void
    {
        $this->assertSame(11.0, Markup::price(10, 'percent', 10));
        $this->assertSame(12.5, Markup::price(10, 'fixed', 2.5));
    }

    public function test_redaction(): void
    {
        $this->assertSame('[redacted]', Redactor::payload(['api-key' => 'x'])['api-key']);
        $this->assertStringNotContainsString('abc', Redactor::text('auth-code=abc&x=1'));
    }

    public function test_nameserver_validation(): void
    {
        $this->assertSame(['ns1.a.com', 'ns2.a.com'], NameserverService::validate(['NS1.a.com', ' ns2.a.com']));
        $this->expectException(\InvalidArgumentException::class);
        NameserverService::validate(['only.one.com']);
    }
}
