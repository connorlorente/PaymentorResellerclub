<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\ContactTypes;
use Paymenter\Extensions\Servers\ResellerClub\Core\RegistrationService;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class ContactTypesTest extends TestCase
{
    public function test_uk_family_needs_a_uk_contact(): void
    {
        foreach (['shop.co.uk', 'shop.uk', 'shop.org.uk', 'SHOP.ME.UK', ' shop.co.uk '] as $domain) {
            $this->assertSame('UkContact', ContactTypes::forDomain($domain), $domain);
        }
    }

    public function test_other_extensions_keep_the_generic_contact(): void
    {
        foreach (['shop.com', 'shop.net', 'shop.org', 'shop.io', 'nodots', ''] as $domain) {
            $this->assertSame('Contact', ContactTypes::forDomain($domain), $domain);
        }
    }

    public function test_registration_creates_the_contact_with_the_type_for_the_domain(): void
    {
        $client = new FakeRegistrarClient();
        $svc = new RegistrationService($client, new InMemoryOperationStore(), true);
        $svc->register(1, 'shop.co.uk', 1, ['ns1.a.com', 'ns2.a.com'], ['email' => 'a@b.c'], ['name' => 'A']);
        $this->assertSame('UkContact', $client->lastContactType);

        $svc->register(2, 'shop.com', 1, ['ns1.a.com', 'ns2.a.com'], ['email' => 'a@b.c'], ['name' => 'A']);
        $this->assertSame('Contact', $client->lastContactType);
    }
}
