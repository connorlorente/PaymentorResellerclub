<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/** Normalises the reseller-cost-price.json response to tld => [years => cost]. */
final class CostTable
{
    /** Product keys that do not follow the `dot<tld>` pattern (seen in the test-host response). */
    private const ALIASES = [
        'domcno' => 'com', 'domorg' => 'org', 'dombiz' => 'biz', 'dominfo' => 'info', 'domus' => 'us',
        'thirdleveldotuk' => 'co.uk', 'dotcoza' => 'co.za',
    ];

    /**
     * Confirmed shape (test host): entries keyed by product key, each with `addnewdomain` => [years => "price"].
     * There is no TLD field, so the TLD comes from the alias list or from keys like "dotnet"; other keys are skipped.
     *
     * @return array<string,array<int,float>>
     */
    public static function parse(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $entry) {
            if (!is_array($entry) || !is_array($entry['addnewdomain'] ?? null)) {
                continue;
            }
            $key = (string) $key;
            $tld = self::ALIASES[$key] ?? (str_starts_with($key, 'dot') ? substr($key, 3) : '');
            $tld = strtolower(ltrim((string) ($entry['tld'] ?? $tld), '.'));
            if ($tld === '') {
                continue;
            }
            foreach ($entry['addnewdomain'] as $years => $cost) {
                if (is_numeric($years) && is_numeric($cost) && (float) $cost > 0) {
                    $out[$tld][(int) $years] = (float) $cost;
                }
            }
        }

        return $out;
    }
}
