<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\CartDomain;

class CartDomainTest extends TestCase
{
    public function test_domain_product_describes_register_and_transfer(): void
    {
        $this->assertSame(['domain' => 'shop.co.uk', 'label' => 'shop.co.uk (new registration)'],
            CartDomain::describe(['domain_action' => 'register', 'domain_name' => 'Shop.co.uk '], true));
        $this->assertSame('shop.co.uk (transfer in)', CartDomain::describe(['domain_action' => 'transfer', 'domain_name' => 'shop.co.uk'], true)['label']);
    }

    public function test_hosting_item_describes_its_domain_choice(): void
    {
        $this->assertSame('my.org (your own domain)', CartDomain::describe(['domain_option' => 'own', 'domain' => 'my.org'], false)['label']);
        $this->assertSame('new.com (new registration)', CartDomain::describe(['domain_option' => 'register', 'domain' => 'new.com'], false)['label']);
    }

    public function test_plain_hosting_without_a_domain_choice_is_left_alone(): void
    {
        $this->assertNull(CartDomain::describe(['domain' => 'plain-plesk.co.uk'], false));
        $this->assertNull(CartDomain::describe(['domain_option' => 'own', 'domain' => ' '], false));
    }

    public function test_bundled_domain_checkout_never_contains_null_values(): void
    {
        // properties.value is NOT NULL: a null here makes the whole order fail and roll back.
        foreach ([CartDomain::domainCheckout('register', 'a.co.uk'), CartDomain::domainCheckout('transfer', 'a.co.uk', 'EPP'), CartDomain::domainCheckout('transfer', 'a.co.uk', null)] as $fields) {
            foreach ($fields as $k => $v) {
                $this->assertNotNull($v, "checkout field $k must not be null");
            }
        }
        $this->assertSame(['domain_action' => 'register', 'domain_name' => 'a.co.uk'], CartDomain::domainCheckout('register', 'a.co.uk', 'ignored'));
        $this->assertSame('EPP', CartDomain::domainCheckout('transfer', 'a.co.uk', 'EPP')['auth_code']);
    }

    public function test_option_has_everything_the_cart_and_order_code_read(): void
    {
        $o = CartDomain::option('a.com', 'a.com (new registration)');
        foreach (['option_id', 'option_name', 'option_type', 'option_env_variable', 'value', 'value_name'] as $k) {
            $this->assertArrayHasKey($k, $o);
        }
        $this->assertSame('text', $o['option_type']); // becomes a service property, never a config row
        $this->assertSame('domain', $o['option_env_variable']);
        $this->assertNull($o['option_id']);
    }

    public function test_merge_keeps_real_options_and_never_duplicates_the_domain_line(): void
    {
        $real = ['option_id' => 5, 'option_name' => 'Disk', 'option_type' => 'select', 'value' => 9, 'value_name' => '10 GB'];
        $d = ['domain' => 'a.com', 'label' => 'a.com (new registration)'];

        $once = CartDomain::merge([$real], $d);
        $twice = CartDomain::merge($once, $d);
        $this->assertSame($once, $twice);
        $this->assertCount(2, $twice);
        $this->assertSame($real, $twice[0]);

        // A changed choice replaces the old line; no choice removes it.
        $changed = CartDomain::merge($twice, ['domain' => 'b.com', 'label' => 'b.com (transfer in)']);
        $this->assertCount(2, $changed);
        $this->assertSame('b.com', $changed[1]['value']);
        $this->assertSame([$real], CartDomain::merge($changed, null));
    }

    public function test_a_real_config_option_that_happens_to_be_called_domain_is_not_removed(): void
    {
        $real = ['option_id' => 7, 'option_name' => 'Domain', 'option_type' => 'text', 'value' => 'x', 'value_name' => 'x'];
        $this->assertSame([$real], CartDomain::merge([$real], null));
    }
}
