<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

final class Markup
{
    /** @param string $type 'percent' or 'fixed' */
    public static function price(float $cost, string $type, float $value, float $fxRate = 1.0): float
    {
        $sell = $type === 'fixed' ? $cost + $value : $cost * (1 + $value / 100);

        return round($sell * $fxRate, 2);
    }
}
