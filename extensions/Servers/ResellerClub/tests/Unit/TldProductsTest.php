<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldProducts;

class TldProductsTest extends TestCase
{
    public function test_normalise_accepts_dots_and_case_and_rejects_junk(): void
    {
        $this->assertSame('com', TldProducts::normalise(' .COM '));
        $this->assertSame('co.uk', TldProducts::normalise('co.uk'));
        foreach (['', 'a b', 'com/', '-x', 'a..b'] as $bad) {
            try {
                TldProducts::normalise($bad);
                $this->fail("accepted '$bad'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_terms_use_registrar_terms_when_known_else_one_to_max(): void
    {
        $costs = ['com' => [1 => 8.0, 2 => 16.0, 5 => 40.0, 10 => 80.0]];
        $this->assertSame([1, 2, 5], TldProducts::terms($costs, 'com', 5));
        $this->assertSame([1, 2, 3], TldProducts::terms([], 'io', 3));
        $this->assertCount(10, TldProducts::terms([], 'io', 99)); // capped at 10
    }
}
