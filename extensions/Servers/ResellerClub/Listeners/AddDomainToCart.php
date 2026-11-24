<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Listeners;

use App\Events\CartItem\Created;
use App\Events\CartItem\Deleted;
use App\Events\CartItem\Updated;
use App\Models\CartItem;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\Core\CartBundle;
use Paymenter\Extensions\Servers\ResellerClub\Core\CartDomain;
use Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainOrderable;

/**
 * WHMCS-style bundling and display:
 *  - a hosting item (Plesk + Domains module: domain_option, domain, domain_auth_code) with "register" or "transfer"
 *    chosen gets the matching .tld domain product added next to it, so the customer pays for both in one order;
 *  - the pair stays in step: editing the hosting item replaces or removes its domain item, removing the hosting item
 *    removes its domain item, and removing just the domain item turns the hosting back to "I use my own domain";
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
        $this->sync($event->cartItem);
    }

    /** Item edited in the cart: Paymenter rebuilds its config options, so re-sync the pair and put the Domain line back. */
    public function handleUpdated(Updated $event): void
    {
        $this->sync($event->cartItem);
    }

    /** Item removed from the cart: take its partner with it, or release the hosting from a domain that is gone. */
    public function handleDeleted(Deleted $event): void
    {
        try {
            $item = $event->cartItem;
            $item->loadMissing('product.server');
            if (!$item->cart_id) {
                return;
            }
            if ($this->isDomainProduct($item)) {
                $hostingId = (int) (((array) $item->checkout_config)[CartBundle::MARKER] ?? 0);
                $hosting = $hostingId ? CartItem::where('cart_id', $item->cart_id)->find($hostingId) : null;
                if ($hosting) {
                    $cfg = (array) $hosting->checkout_config;
                    if (in_array($cfg[self::OPTION_FIELD] ?? null, [DomainOrderable::REGISTER, DomainOrderable::TRANSFER], true)) {
                        $cfg[self::OPTION_FIELD] = DomainOrderable::OWN;
                        unset($cfg[self::AUTH_FIELD]);
                        $hosting->checkout_config = $cfg;
                        $hosting->saveQuietly();
                        $this->showDomain($hosting);
                    }
                }

                return;
            }
            foreach ($this->bundledItems($item) as $bundled) {
                $bundled->deleteQuietly();
            }
        } catch (\Throwable $e) {
            SafeReport::report($e);
        }
    }

    private function sync(CartItem $item): void
    {
        try {
            $item->loadMissing('product.server');
            if (!$this->isDomainProduct($item)) {
                $this->syncBundled($item);
            }
            $this->showDomain($item);
        } catch (\Throwable $e) {
            SafeReport::report($e);
        }
    }

    private function isDomainProduct(CartItem $item): bool
    {
        return $item->product?->server?->extension === 'ResellerClub';
    }

    /** Domain items in the same cart that this hosting item added. @return CartItem[] */
    private function bundledItems(CartItem $hosting): array
    {
        if (!$hosting->cart_id) {
            return [];
        }
        $out = [];
        foreach (CartItem::where('cart_id', $hosting->cart_id)->with('product.server')->get() as $e) {
            if ($this->isDomainProduct($e) && (string) (((array) $e->checkout_config)[CartBundle::MARKER] ?? '') === (string) $hosting->id) {
                $out[] = $e;
            }
        }

        return $out;
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

    private function syncBundled(CartItem $item): void
    {
        $cfg = (array) $item->checkout_config;
        $option = $cfg[self::OPTION_FIELD] ?? null;
        $domain = strtolower(trim((string) ($cfg[self::DOMAIN_FIELD] ?? '')));
        $parts = AvailabilityChecker::split($domain);
        $product = $parts ? (ResellerClub::sellableProducts()[$parts[1]] ?? null) : null;
        $auth = trim((string) ($cfg[self::AUTH_FIELD] ?? ''));

        $existing = [];
        $byId = [];
        foreach ($this->bundledItems($item) as $b) {
            $bc = (array) $b->checkout_config;
            $existing[$b->id] = ['domain' => strtolower((string) ($bc['domain_name'] ?? '')), 'action' => (string) ($bc['domain_action'] ?? ''), 'auth' => (string) ($bc['auth_code'] ?? '')];
            $byId[$b->id] = $b;
        }

        $decision = CartBundle::decide(is_string($option) ? $option : null, $product ? $domain : null, $existing, $auth);
        foreach ($decision['delete'] as $id) {
            $byId[$id]->deleteQuietly();
        }
        if ($decision['keep'] !== null && $decision['auth'] !== null) {
            $kept = $byId[$decision['keep']];
            $bc = (array) $kept->checkout_config;
            $bc['auth_code'] = $decision['auth'];
            $kept->checkout_config = $bc;
            $kept->saveQuietly();
        }
        if ($decision['create'] && $product) {
            $this->addDomainItem($item, $product, (string) $option, $domain, $auth);
        }

        // The domain item carries the auth code now; don't also store it on the hosting service.
        if ($product && array_key_exists(self::AUTH_FIELD, $cfg) && in_array($option, [DomainOrderable::REGISTER, DomainOrderable::TRANSFER], true)) {
            unset($cfg[self::AUTH_FIELD]);
            $item->checkout_config = $cfg;
            $item->saveQuietly();
        }
    }

    private function addDomainItem(CartItem $hosting, $product, string $option, string $domain, string $auth): void
    {
        // Work on the hosting item's OWN cart. Cart::add()/Cart::items() find the cart through the request cookie,
        // which does not exist yet on a visitor's first item (the cookie is only queued): they would open a
        // second cart and the hosting item would be lost.
        $cart = $hosting->cart;
        if (!$cart || $cart->items()->count() >= self::MAX_ITEMS) {
            return;
        }

        // Already in the cart as its own item (the customer added it from the search page)? Then there is nothing to add.
        foreach ($cart->items()->get() as $existing) {
            $ec = (array) $existing->checkout_config;
            if ($existing->product_id === $product->id && strtolower((string) ($ec['domain_name'] ?? '')) === $domain && !isset($ec[CartBundle::MARKER])) {
                return;
            }
        }

        $plans = $product->plans->sortBy('billing_period');
        $plan = $plans->first(fn ($p) => $p->billing_unit === 'year' && (int) $p->billing_period === 1) ?? $plans->first();
        if (!$plan) {
            return;
        }

        $checkout = CartDomain::domainCheckout($option, $domain, $auth, (string) $hosting->id);

        // Created with its Domain line already in place (the created-event then finds nothing left to change).
        $cart->items()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'config_options' => CartDomain::merge([], CartDomain::describe($checkout, true)),
            'checkout_config' => $checkout,
            'quantity' => 1,
        ]);
    }
}
