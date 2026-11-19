<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainAvailable;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class AvailabilityTest extends TestCase
{
    private FakeRegistrarClient $client;
    private AvailabilityChecker $checker;
    private array $cache = [];

    protected function setUp(): void
    {
        $this->client = new FakeRegistrarClient();
        $this->checker = new AvailabilityChecker(
            $this->client,
            fn ($k) => $this->cache[$k] ?? null,
            function ($k, $v, $ttl) {
                $this->cache[$k] = $v;
            },
        );
    }

    public function test_statuses_are_simplified_and_cached(): void
    {
        $this->client->availability = ['a.com' => 'available', 'a.net' => 'regthroughothers', 'a.io' => 'premium', 'a.xyz' => 'unknown'];
        $out = $this->checker->check('a', ['com', 'net', 'io', 'xyz']);
        $this->assertSame(['com' => 'available', 'net' => 'taken', 'io' => 'premium', 'xyz' => 'unknown'], $out);

        $this->checker->check('a', ['com', 'net', 'io', 'xyz']);
        $this->assertSame(2, $this->client->count('checkAvailability') - 0); // 2nd call only re-asks for the uncached 'unknown'
    }

    public function test_tlds_are_requested_in_groups_of_five(): void
    {
        $this->checker->check('a', ['a1', 'a2', 'a3', 'a4', 'a5', 'a6']);
        $this->assertSame(2, $this->client->count('checkAvailability'));
    }

    public function test_outage_yields_unknown_and_is_not_cached(): void
    {
        $this->client->availabilityFails = true;
        $this->assertSame(['com' => 'unknown'], $this->checker->check('a', ['com']));
        $this->assertSame([], $this->cache);
    }

    private function errors(string $value, string $tld = 'com'): array
    {
        $errors = [];
        (new DomainAvailable($this->checker, $tld))->validate('domain_name', $value, function ($m) use (&$errors) {
            $errors[] = $m;
        });

        return $errors;
    }

    public function test_checkout_rule(): void
    {
        $this->client->availability = ['free.com' => 'available', 'gone.com' => 'regthroughus'];
        $this->assertSame([], $this->errors('Free.com'));
        $this->assertStringContainsString('no longer available', $this->errors('gone.com')[0]);
        $this->assertStringContainsString('.com domains only', $this->errors('free.net')[0]); // wrong product TLD
        $this->assertStringContainsString('valid domain', $this->errors('not a domain')[0]);
        $this->assertStringContainsString('could not check', strtolower($this->errors('other.com')[0])); // missing => unknown
    }
}
