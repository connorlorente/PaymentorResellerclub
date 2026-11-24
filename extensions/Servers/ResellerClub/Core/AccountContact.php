<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * Builds the registrar contact from the customer's Paymenter account (the standard user properties:
 * phone, company_name, address, address2, city, state, zip, country), WHMCS style, so checkout does
 * not ask for the registrant again. Framework-free so it can be unit tested.
 */
class AccountContact
{
    /** Calling codes for the countries this business mostly sells to; anything else needs a +cc in the phone field. */
    private const CALLING_CODES = [
        'GB' => '44', 'IE' => '353', 'US' => '1', 'CA' => '1', 'AU' => '61', 'NZ' => '64', 'FR' => '33', 'DE' => '49',
        'ES' => '34', 'IT' => '39', 'NL' => '31', 'BE' => '32', 'PT' => '351', 'PL' => '48', 'SE' => '46', 'NO' => '47',
        'DK' => '45', 'FI' => '358', 'CH' => '41', 'AT' => '43', 'IN' => '91', 'PK' => '92', 'BD' => '880', 'AE' => '971',
        'ZA' => '27', 'NG' => '234', 'SG' => '65', 'HK' => '852', 'JP' => '81', 'CN' => '86',
    ];

    /** Fields the registrar insists on. */
    public const REQUIRED = ['name', 'email', 'address', 'city', 'country', 'zip', 'phone_cc', 'phone'];

    /**
     * @param array<string,mixed> $props user properties keyed by property key
     * @return array{contact: array<string,string|null>, missing: string[]}
     */
    public static function build(string $name, string $email, array $props): array
    {
        $p = fn (string $k) => ($v = trim((string) ($props[$k] ?? ''))) === '' ? null : $v;

        $country = strtoupper((string) $p('country')) ?: null;
        [$cc, $phone] = self::splitPhone((string) $p('phone'), $country);

        $address = trim(implode(', ', array_filter([$p('address'), $p('address2')])));
        // A migrated account can have only address2 filled (seen on live data): use it as line 1.
        $contact = [
            'name' => trim($name) ?: null,
            'email' => trim($email) ?: null,
            'company' => $p('company_name'),
            'address' => $address === '' ? null : $address,
            'city' => $p('city'),
            'state' => $p('state'),
            'country' => $country,
            'zip' => $p('zip'),
            'phone_cc' => $cc,
            'phone' => $phone,
        ];

        $missing = array_values(array_filter(self::REQUIRED, fn ($k) => $contact[$k] === null));

        return ['contact' => $contact, 'missing' => $missing];
    }

    /**
     * "+44 7700 900123" -> ['44', '7700900123']; "07700 900123" with country GB -> ['44', '7700900123'].
     *
     * @return array{0:?string, 1:?string}
     */
    public static function splitPhone(string $raw, ?string $country): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return [null, null];
        }

        if ($international) {
            // Longest matching known code first (e.g. 353 before 3).
            $codes = array_unique(array_values(self::CALLING_CODES));
            usort($codes, fn ($a, $b) => strlen($b) <=> strlen($a));
            $own = $country ? (self::CALLING_CODES[$country] ?? null) : null;
            if ($own && str_starts_with($digits, $own)) {
                return [$own, substr($digits, strlen($own))];
            }
            foreach ($codes as $code) {
                if (str_starts_with($digits, $code)) {
                    return [$code, substr($digits, strlen($code))];
                }
            }

            return [null, null]; // unknown code: better to ask than to guess
        }

        $cc = $country ? (self::CALLING_CODES[$country] ?? null) : null;
        if ($cc === null) {
            return [null, null];
        }

        return [$cc, ltrim($digits, '0')]; // national format: drop the trunk prefix
    }
}
