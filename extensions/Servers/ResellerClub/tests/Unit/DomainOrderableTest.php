<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainOrderable;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class DomainOrderableTest extends TestCase
{
    private function rule(array $availability, string $option): array
    {
        $client = new FakeRegistrarClient();
        $client->availability = $availability;
        $checker = new AvailabilityChecker($client, fn () => null, fn () => null);
        $rule = (new DomainOrderable($checker, ['co.uk', 'com'], 'domain_option'))->setData(['checkoutConfig' => ['domain_option' => $option]]);

        return [$rule, $client];
    }

    private function errors(DomainOrderable $rule, string $value): array
    {
        $errors = [];
        $rule->validate('checkoutConfig.domain', $value, function ($msg) use (&$errors) {
            $errors[] = $msg;
        });

        return $errors;
    }

    public function test_register_accepts_free_name(): void
    {
        [$rule] = $this->rule(['shop.co.uk' => 'available'], 'register');
        $this->assertSame([], $this->errors($rule, 'shop.co.uk'));
    }

    public function test_register_rejects_taken_name_and_suggests_transfer(): void
    {
        [$rule] = $this->rule(['shop.co.uk' => 'regthroughothers'], 'register');
        $this->assertStringContainsString('Transfer', $this->errors($rule, 'shop.co.uk')[0]);
    }

    public function test_transfer_requires_registered_name(): void
    {
        [$rule] = $this->rule(['shop.co.uk' => 'regthroughothers'], 'transfer');
        $this->assertSame([], $this->errors($rule, 'shop.co.uk'));

        [$rule] = $this->rule(['shop.co.uk' => 'available'], 'transfer');
        $this->assertStringContainsString('not registered', $this->errors($rule, 'shop.co.uk')[0]);
    }

    public function test_unsold_tld_is_rejected_for_register(): void
    {
        [$rule, $client] = $this->rule([], 'register');
        $this->assertStringContainsString(".io", $this->errors($rule, 'shop.io')[0]);
        $this->assertSame(0, $client->count('checkAvailability')); // no registrar call for TLDs we don't sell
    }

    public function test_own_domain_only_checks_the_format(): void
    {
        [$rule, $client] = $this->rule([], 'own');
        $this->assertSame([], $this->errors($rule, 'anything.io'));
        $this->assertNotEmpty($this->errors($rule, 'http://bad'));
        $this->assertSame(0, $client->count('checkAvailability'));
    }

    public function test_unknown_option_falls_back_to_default(): void
    {
        $this->assertSame('register', DomainOrderable::option(['checkoutConfig' => ['domain_option' => 'hack']], 'domain_option'));
    }
}
