<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Listeners;

use App\Events\CartItem\Created;
use App\Events\CartItem\Updated;
use App\Models\CartItem;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\Core\CartDomain;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainOrderable;

/**
 * WHMCS-style bundling and display:
 *  - a hosting item (Plesk + Domains module: domain_option, domain, domain_auth_code) with "register" or "transfer"
 *    chosen gets the matching .tld domain product added next to it, so the customer pays for both in one order;
 *  - every item that carries a domain choice shows it on its cart line ("Domain: example.co.uk (new registration)").
 *
 * Never throws: a problem here must not stop an item from being added to the cart.
 */
class AddDomainToCart
{
    public const OPTION_FIELD = 'domain_option';
    public const DOMAIN_FIELD = 'domain';
    public const AUTH_FIELD = 'domain_auth_code';

    /** Paymenter's own cart size limit (App\Classes\Cart::DEFAULT_MAX_ITEMS). */
    private const MAX_ITEMS = 15;

    /** New cart item: bundle the domain, then show the domain on the line. */
    public function handle(Created $event): void
    {
        try {
            $item = $event->cartItem;
            $item->loadMissing('product.server');
            if (!$this->isDomainProduct($item)) {
                $this->bundleDomain($item);
            }
            $this->showDomain($item);
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }
    }

    /** Item edited in the cart: Paymenter rebuilds its config options, so put the Domain line back. */
    public function handleUpdated(Updated $event): void
    {
        try {
            $item = $event->cartItem;
            $item->loadMissing('product.server');
            $this->showDomain($item);
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }
    }

    private function isDomainProduct(CartItem $item): bool
    {
        return $item->product?->server?->extension === 'ResellerClub';
    }

    /** Keeps exactly one up-to-date "Domain" line in the item's config options. */
    private function showDomain(CartItem $item): void
    {
        $described = CartDomain::describe((array) $item->checkout_config, $this->isDomainProduct($item));
        $current = (array) $item->config_options;
        $merged = CartDomain::merge($current, $described);
        if ($merged !== $current) {
            $item->config_options = $merged;
            $item->saveQuietly();
        }
    }

    private function bundleDomain(CartItem $item): void
    {
        $cfg = (array) $item->checkout_config;
        $option = $cfg[self::OPTION_FIELD] ?? null;
        if (!in_array($option, [DomainOrderable::REGISTER, DomainOrderable::TRANSFER], true)) {
            return;
        }
        $domain = strtolower(trim((string) ($cfg[self::DOMAIN_FIELD] ?? '')));
        $parts = AvailabilityChecker::split($domain);
        if (!$parts) {
            return;
        }
        $product = ResellerClub::sellableProducts()[$parts[1]] ?? null;
        if (!$product) {
            return;
        }

        // Work on the hosting item's OWN cart. Cart::add()/Cart::items() find the cart through the request cookie,
        // which does not exist yet on a visitor's first item (the cookie is only queued): they would open a
        // second cart and the hosting item would be lost.
        $cart = $item->cart;
        if (!$cart || $cart->items()->count() >= self::MAX_ITEMS) {
            return;
        }

        // Already in the cart (customer added it from the search page, or re-added the hosting)?
        foreach ($cart->items()->get() as $existing) {
            if ($existing->product_id === $product->id && strtolower((string) (((array) $existing->checkout_config)['domain_name'] ?? '')) === $domain) {
                return;
            }
        }

        $plans = $product->plans->sortBy('billing_period');
        $plan = $plans->first(fn ($p) => $p->billing_unit === 'year' && (int) $p->billing_period === 1) ?? $plans->first();
        if (!$plan) {
            return;
        }

        $checkout = CartDomain::domainCheckout($option, $domain, (string) ($cfg[self::AUTH_FIELD] ?? ''));

        // Created with its Domain line already in place (the created-event then finds nothing left to change).
        $cart->items()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'config_options' => CartDomain::merge([], CartDomain::describe($checkout, true)),
            'checkout_config' => $checkout,
            'quantity' => 1,
        ]);

        // The domain item carries the auth code now; don't also store it on the hosting service.
        if (array_key_exists(self::AUTH_FIELD, $cfg)) {
            unset($cfg[self::AUTH_FIELD]);
            $item->checkout_config = $cfg;
            $item->saveQuietly();
        }
    }
}
