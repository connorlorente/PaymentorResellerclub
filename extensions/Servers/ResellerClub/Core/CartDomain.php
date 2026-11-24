<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * Makes the chosen domain visible on a cart line, WHMCS style ("Domain: example.co.uk (new registration)").
 *
 * Paymenter's cart prints each item's config options as "name: value_name", and at order time turns an option
 * of type "text" into a service property (key = env variable or name). So a pseudo "Domain" text option does both
 * jobs safely: it shows in the cart and creates the `domain` property that the invoice line wording reads.
 * Pure functions, no framework, so they can be unit tested.
 */
class CartDomain
{
    public const OPTION_NAME = 'Domain';
    public const PROPERTY_KEY = 'domain';

    /**
     * @param array<string,mixed> $checkoutConfig the cart item's checkout_config
     * @return array{domain:string, label:string}|null null when the item carries no domain choice
     */
    public static function describe(array $checkoutConfig, bool $isDomainProduct): ?array
    {
        if ($isDomainProduct) {
            $domain = $checkoutConfig['domain_name'] ?? null;
            $mode = $checkoutConfig['domain_action'] ?? 'register';
        } else {
            // Hosting on the Plesk + Domains module: only items that actually made a domain choice.
            if (empty($checkoutConfig['domain_option'])) {
                return null;
            }
            $domain = $checkoutConfig['domain'] ?? null;
            $mode = $checkoutConfig['domain_option'];
        }
        $domain = strtolower(trim((string) $domain));
        if ($domain === '') {
            return null;
        }

        $suffix = match ($mode) {
            'transfer' => ' (transfer in)',
            'own' => ' (your own domain)',
            default => ' (new registration)',
        };

        return ['domain' => $domain, 'label' => $domain . $suffix];
    }

    /**
     * Checkout fields of a bundled domain item. Paymenter stores every checkout field as a service property in a
     * NOT NULL column, so a null (e.g. an optional field left blank) would make the whole order fail: never emit one.
     *
     * @return array<string,string>
     */
    public static function domainCheckout(string $action, string $domain, ?string $authCode = null): array
    {
        $fields = ['domain_action' => $action, 'domain_name' => $domain];
        if ($action === 'transfer') {
            $fields['auth_code'] = (string) $authCode;
        }

        return $fields;
    }

    /** The pseudo option in the shape the cart and order code expect. */
    public static function option(string $domain, string $label): array
    {
        return [
            'option_id' => null,
            'option_name' => self::OPTION_NAME,
            'option_type' => 'text',
            'option_env_variable' => self::PROPERTY_KEY,
            'value' => $domain,
            'value_name' => $label,
        ];
    }

    /**
     * Returns the config options with exactly one up-to-date Domain line, leaving every real option untouched.
     *
     * @param array<int,array<string,mixed>> $options
     * @return array<int,array<string,mixed>>
     */
    public static function merge(array $options, ?array $described): array
    {
        $kept = array_values(array_filter($options, fn ($o) => !(is_array($o) && ($o['option_name'] ?? null) === self::OPTION_NAME && ($o['option_id'] ?? null) === null)));
        if ($described) {
            $kept[] = self::option($described['domain'], $described['label']);
        }

        return $kept;
    }
}
