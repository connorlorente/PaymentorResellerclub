<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Listeners;

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Home page block: a domain search box plus the extensions on sale. Renders nothing when domains are not for sale. */
class HomeDomainSearch
{
    public function __invoke(): array
    {
        try {
            $server = Server::where('extension', 'ResellerClub')->first();
            if (!$server || !ResellerClub::forServer($server)->registrationsAllowed()) {
                return [];
            }
            $tlds = [];
            foreach (Product::where('server_id', $server->id)->where('hidden', false)->with(['settings', 'plans.prices'])->get() as $product) {
                $tld = ExtensionHelper::settingsToArray($product->settings)['tld'] ?? null;
                if ($tld && $product->price()->available) {
                    // "£14.99GBP" -> "£14.99": the currency symbol already says it, and the code made the tiles messy.
                    $tlds[strtolower($tld)] = preg_replace('/\s*[A-Z]{3}$/', '', (string) $product->price());
                }
            }
            if (!$tlds) {
                return [];
            }
            ksort($tlds);

            return ['view' => view('resellerclub::home-search', ['tlds' => $tlds])->render()];
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e); // never break the home page

            return [];
        }
    }
}
