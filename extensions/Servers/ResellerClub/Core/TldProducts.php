<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Creates one product per TLD with a yearly-unit plan for each registration term. Safe to run twice. */
class TldProducts
{
    /** @throws \InvalidArgumentException */
    public static function normalise(string $tld): string
    {
        $tld = strtolower(trim(ltrim(trim($tld), '.')));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $tld) || strlen($tld) > 63) {
            throw new \InvalidArgumentException("Invalid TLD: $tld");
        }

        return $tld;
    }

    /** Terms to offer: the registrar's own terms when the cost table knows the TLD, otherwise 1..$maxYears. */
    public static function terms(array $costs, string $tld, int $maxYears): array
    {
        $maxYears = max(1, min(10, $maxYears));
        $known = array_keys($costs[$tld] ?? []);
        $terms = $known ? array_values(array_filter($known, fn ($y) => $y >= 1 && $y <= $maxYears)) : range(1, $maxYears);
        sort($terms);

        return $terms;
    }

    /**
     * @return array{product_id:int, created:bool, terms:int[], priced:bool}
     */
    public function create(string $tld, int $categoryId, Server $server, int $maxYears = 10): array
    {
        $tld = self::normalise($tld);

        $existing = Product::where('server_id', $server->id)
            ->whereHas('settings', fn ($q) => $q->where('key', 'tld')->where('value', $tld))->first();
        if ($existing) {
            return ['product_id' => $existing->id, 'created' => false, 'terms' => [], 'priced' => false];
        }

        $ext = ResellerClub::forServer($server);
        $costs = Cache::get("resellerclub.costs.{$server->id}") ?: (new PricingSyncRunner())->costs($ext, $server->id); // fetch once when nothing is cached
        $terms = self::terms($costs, $tld, $maxYears);
        $markup = PriceSync::markupFor(['markup_type' => $ext->config('markup_type'), 'markup_value' => $ext->config('markup_value')], []);
        $fx = (float) ($ext->config('fx_rate') ?: 1);
        $currency = config('settings.default_currency');

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => ".$tld domain",
            'slug' => 'domain-' . Str::slug($tld),
            'description' => "Register a .$tld domain name.",
            'allow_quantity' => 'disabled', // one name per line item
            'server_id' => $server->id,
        ]);
        $product->settings()->createMany([
            ['key' => 'tld', 'value' => $tld, 'type' => 'string'],
            ['key' => 'years', 'value' => '1', 'type' => 'string'],
        ]);

        $priced = false;
        foreach ($terms as $years) {
            $plan = $product->plans()->create([
                'name' => $years === 1 ? '1 year' : "$years years",
                'type' => 'recurring',
                'billing_period' => $years,
                'billing_unit' => 'year',
                'sort' => $years,
            ]);
            if (isset($costs[$tld][$years])) {
                Price::create(['plan_id' => $plan->id, 'currency_code' => $currency, 'price' => Markup::price($costs[$tld][$years], $markup[0], $markup[1], $fx)]);
                $priced = true;
            }
        }

        return ['product_id' => $product->id, 'created' => true, 'terms' => $terms, 'priced' => $priced];
    }
}
