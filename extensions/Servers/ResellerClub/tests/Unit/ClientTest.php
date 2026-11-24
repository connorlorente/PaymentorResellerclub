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

    public function test_cloudflare_block_page_is_not_reported_as_whitelist_problem(): void
    {
        $html = '<html><title>Attention Required! | Cloudflare</title><span>Cloudflare Ray ID: <strong class="font-semibold">a5f398d04d8abf0d</strong></span>'
            . '<span class="hidden" id="cf-footer-ip">203.0.113.9</span></html>';
        $c = $this->client(['status' => 403, 'body' => $html]);
        try {
            $c->ping();
            $this->fail('expected exception');
        } catch (RegistrarException $e) {
            $this->assertSame(RegistrarException::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('Cloudflare', $e->getMessage());
            $this->assertStringContainsString('203.0.113.9', $e->getMessage());
            $this->assertStringContainsString('a5f398d04d8abf0d', $e->getMessage());
        }
    }

    public function test_plain_403_is_still_the_whitelist_problem(): void
    {
        $c = $this->client(['status' => 403, 'body' => 'Access Denied. You are not allowed to perform this action.']);
        try {
            $c->ping();
            $this->fail('expected exception');
        } catch (RegistrarException $e) {
            $this->assertSame(RegistrarException::IP_NOT_WHITELISTED, $e->kind);
        }
    }

    public function test_transfer_sends_auth_code_and_contacts_but_no_years(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"entityid":77,"eaqid":88,"actionstatus":"InvoicePaid"}'], $seen);
        $r = $c->transfer(new RegisterRequest('mine.co.uk', 1, [], 11, 22), 'EPP-SECRET');
        $this->assertSame('https://test.httpapi.com/api/domains/transfer.json', $seen['url']);
        $this->assertSame('EPP-SECRET', $seen['params']['auth-code']);
        $this->assertArrayNotHasKey('years', $seen['params']);
        $this->assertArrayNotHasKey('ns', $seen['params']); // keep the current nameservers when none were given
        $this->assertSame(22, $seen['params']['reg-contact-id']);
        $this->assertSame(-1, $seen['params']['admin-contact-id']); // .uk needs -1
        $this->assertSame(77, $r->orderId);
    }

    public function test_api_errors_name_the_failing_call_and_its_non_secret_ids_only(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"status":"error","error":"Invalid domainName For Given ContactType"}']);
        try {
            $c->register(new RegisterRequest('shop.co.uk', 1, ['ns1.a.com', 'ns2.a.com'], 11, 22));
            $this->fail('expected exception');
        } catch (RegistrarException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('domains/register.json', $msg);
            $this->assertStringContainsString('domain-name=shop.co.uk', $msg);
            $this->assertStringContainsString('reg-contact-id=22', $msg);
            $this->assertStringContainsString('admin-contact-id=-1', $msg); // .uk sends -1 for the other roles
            $this->assertStringContainsString('Invalid domainName For Given ContactType', $msg);
            $this->assertStringNotContainsString('SECRETKEY', $msg);
            $this->assertStringNotContainsString('auth-userid', $msg);
        }
    }

    public function test_contact_is_created_with_the_requested_type(): void
    {
        $person = ['name' => 'A B', 'email' => 'a@b.c', 'address' => '1 St', 'city' => 'X', 'country' => 'GB', 'zip' => 'AB1 2CD', 'phone_cc' => '44', 'phone' => '7700900123'];

        $c = $this->client(['status' => 200, 'body' => '12345'], $seen);
        $this->assertSame(12345, $c->createContact($person, 9));
        $this->assertSame('Contact', $seen['params']['type']); // unchanged default

        $c->createContact($person, 9, 'UkContact');
        $this->assertSame('contacts/add.json', substr($seen['url'], -17));
        $this->assertSame('UkContact', $seen['params']['type']);
    }

    public function test_register_still_sends_years_and_nameservers(): void
    {
        $c = $this->client(['status' => 200, 'body' => '{"entityid":1,"eaqid":2}'], $seen);
        $c->register(new RegisterRequest('new.com', 2, ['ns1.a.com', 'ns2.a.com'], 11, 22));
        $this->assertSame('https://test.httpapi.com/api/domains/register.json', $seen['url']);
        $this->assertSame(2, $seen['params']['years']);
        $this->assertSame(['ns1.a.com', 'ns2.a.com'], $seen['params']['ns']);
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
