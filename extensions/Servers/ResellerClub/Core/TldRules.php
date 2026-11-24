<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/** Registry facts the extension needs. Pure, so it is unit tested. */
class TldRules
{
    /** Extensions for which ResellerClub's WHOIS privacy product is not offered (register and renew articles). */
    private const NO_PRIVACY = ['us', 'uk', 'eu', 'de', 'ca', 'fr', 'nl', 'nz', 'ru', 'asia', 'au'];

    /** The last label decides it: co.uk, org.uk and me.uk are all "uk". */
    public static function tldOf(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_contains($domain, '.') ? substr($domain, strrpos($domain, '.') + 1) : $domain;
    }

    public static function supportsPrivacy(string $domain): bool
    {
        return !in_array(self::tldOf($domain), self::NO_PRIVACY, true);
    }

    /** A config option's chosen value as a yes/no ("1", "yes", "true", "on" and a ticked checkbox are yes). */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'no', 'false', 'off', 'null'], true);
    }
}
