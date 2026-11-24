<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use App\Helpers\ExtensionHelper;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/**
 * Scheduled nightly from boot(): caches reseller costs and proposes plan prices.
 * Proposals are only applied when "Auto-apply price changes" is on (default off). Only Price rows change,
 * so invoices that were already issued are never touched.
 */
class PricingSyncRunner
{
    public const PROPOSALS_KEY = 'resellerclub.price_proposals';

    public function __invoke(): void
    {
        $proposals = [];
        foreach (Server::where('extension', 'ResellerClub')->get() as $server) {
            $ext = ResellerClub::forServer($server);
            $costs = $this->costs($ext, $server->id);
            $forServer = $this->proposalsFor($ext, $server, $costs);
            if ($ext->autoApplyPrices()) {
                self::apply($forServer);
            } else {
                $proposals = array_merge($proposals, $forServer); // left for an admin to review
            }
        }
        Cache::forever(self::PROPOSALS_KEY, $proposals);
    }

    /** @return array<string,array<int,float>> */
    public function costs(ResellerClub $ext, int $serverId): array
    {
        // The response is large, so it is cached; a failed fetch keeps serving the last good copy.
        $key = "resellerclub.costs.$serverId";
        try {
            $costs = CostTable::parse($ext->client()->resellerCostPrices());
            if ($costs) {
                Cache::put($key, $costs, now()->addDays(3));
            }
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }

        return Cache::get($key, []);
    }

    /** @return array<int,array<string,mixed>> */
    private function proposalsFor(ResellerClub $ext, Server $server, array $costs): array
    {
        $currency = config('settings.default_currency');
        $serverSettings = ['markup_type' => $ext->config('markup_type'), 'markup_value' => $ext->config('markup_value')];
        $fx = (float) ($ext->config('fx_rate') ?: 1);
        $out = [];

        foreach (Product::where('server_id', $server->id)->with(['settings', 'plans.prices'])->get() as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            if (empty($settings['tld'])) {
                continue;
            }
            $plans = [];
            foreach ($product->plans->where('billing_unit', 'year') as $plan) {
                $plans[] = [
                    'plan_id' => $plan->id, 'tld' => $settings['tld'], 'years' => (int) $plan->billing_period,
                    'current' => ($p = $plan->prices->firstWhere('currency_code', $currency)) ? (float) $p->price : null,
                    'markup' => PriceSync::markupFor($serverSettings, $settings),
                ];
            }
            foreach (PriceSync::propose($costs, $plans, $fx) as $row) {
                $out[] = $row + ['product_id' => $product->id, 'product' => $product->name, 'server_id' => $server->id];
            }
        }

        return $out;
    }

    /** Writes proposed prices to the default currency. @param array<int,array<string,mixed>> $proposals */
    public static function apply(array $proposals): int
    {
        $currency = config('settings.default_currency');
        foreach ($proposals as $p) {
            Price::updateOrCreate(['plan_id' => $p['plan_id'], 'currency_code' => $currency], ['price' => $p['proposed']]);
        }

        return count($proposals);
    }
}
