<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * ResellerClub only accepts a registrant contact of the right TYPE for some extensions: registering a .co.uk with
 * a generic "Contact" fails with "Invalid domainName For Given ContactType". Extensions not listed here use the
 * generic type, as before. Extend the map as other extensions are put on sale.
 */
class ContactTypes
{
    public const GENERIC = 'Contact';

    /** Last label of the domain (the part after the final dot) => contact type. */
    private const BY_EXTENSION = [
        'uk' => 'UkContact', // uk, co.uk, org.uk, me.uk, ltd.uk, plc.uk, net.uk all end in .uk
    ];

    public static function forDomain(string $domain): string
    {
        $tld = strtolower(substr(strrchr(trim($domain), '.') ?: '', 1));

        return self::BY_EXTENSION[$tld] ?? self::GENERIC;
    }
}
