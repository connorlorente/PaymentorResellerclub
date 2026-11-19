<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/** Works out which plan prices should change. Applying them is the caller's job, and only when auto-apply is on. */
final class PriceSync
{
    /**
     * Server defaults overridden per product when the product sets a markup value.
     *
     * @return array{0:string,1:float} [type, value]
     */
    public static function markupFor(array $server, array $product): array
    {
        $hasOverride = isset($product['markup_value']) && $product['markup_value'] !== '' && is_numeric($product['markup_value']);
        $type = $hasOverride ? ($product['markup_type'] ?? null) : ($server['markup_type'] ?? null);
        $value = $hasOverride ? $product['markup_value'] : ($server['markup_value'] ?? 0);

        return [$type === 'fixed' ? 'fixed' : 'percent', (float) $value];
    }

    /**
     * @param array<string,array<int,float>> $costs tld => [years => cost]
     * @param array<int,array{plan_id:int, tld:string, years:int, current:?float, markup:array{0:string,1:float}}> $plans
     * @return array<int,array{plan_id:int, tld:string, years:int, current:?float, proposed:float}> only plans whose price would change
     */
    public static function propose(array $costs, array $plans, float $fxRate = 1.0): array
    {
        $out = [];
        foreach ($plans as $p) {
            $cost = $costs[strtolower($p['tld'])][$p['years']] ?? null;
            if ($cost === null) {
                continue; // the registrar offers no such term: leave the plan alone
            }
            $proposed = Markup::price($cost, $p['markup'][0], $p['markup'][1], $fxRate);
            if ($p['current'] === null || abs($proposed - $p['current']) >= 0.005) {
                $out[] = ['plan_id' => $p['plan_id'], 'tld' => $p['tld'], 'years' => $p['years'], 'current' => $p['current'], 'proposed' => $proposed];
            }
        }

        return $out;
    }
}
