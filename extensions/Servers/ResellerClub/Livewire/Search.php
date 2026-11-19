<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Livewire;

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Livewire\Component;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Public /domains page: availability for the TLDs that have an enabled product, linking into normal checkout. */
class Search extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    public array $results = [];

    public ?string $notice = null;

    public function mount(): void
    {
        if ($this->query !== '') {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->results = [];
        $this->notice = null;

        $server = Server::where('extension', 'ResellerClub')->first();
        if (!$server) {
            abort(404);
        }
        $ext = ResellerClub::forServer($server);
        if (!$ext->registrationsAllowed()) {
            $this->notice = 'New domain registrations are currently unavailable.'; // no availability call is made

            return;
        }

        $key = 'rc-search:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->notice = 'Too many searches. Please wait a minute and try again.';

            return;
        }
        RateLimiter::hit($key, 60);

        $input = strtolower(trim($this->query));
        $parts = AvailabilityChecker::split($input);
        $label = $parts[0] ?? $input;
        $wanted = $parts[1] ?? null;
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            $this->notice = 'Enter a domain name using letters, numbers and hyphens.';

            return;
        }

        // Only TLDs that can actually be bought: a visible product with a price.
        $products = [];
        foreach (Product::where('server_id', $server->id)->where('hidden', false)->with(['settings', 'category', 'plans.prices'])->get() as $product) {
            $tld = ExtensionHelper::settingsToArray($product->settings)['tld'] ?? null;
            if ($tld && $product->price()->available) {
                $products[strtolower($tld)] = $product;
            }
        }
        if (!$products) {
            $this->notice = 'No domain extensions are for sale yet.';

            return;
        }

        $status = $ext->availability()->check($label, array_keys($products));
        foreach ($products as $tld => $product) {
            $s = $status[$tld] ?? 'unknown';
            if ($s === 'premium') {
                continue; // not sold in v1
            }
            $domain = "$label.$tld";
            $this->results[] = [
                'domain' => $domain,
                'status' => $s,
                'price' => $s === 'available' ? (string) $product->price() : null,
                'url' => $s === 'available'
                    ? route('products.checkout', ['category' => $product->category, 'product' => $product, 'config' => ['domain_name' => $domain]])
                    : null,
                'wanted' => $tld === $wanted,
            ];
        }
        usort($this->results, fn ($a, $b) => [!$a['wanted'], $a['status'] !== 'available'] <=> [!$b['wanted'], $b['status'] !== 'available']);
    }

    public function render()
    {
        return view('resellerclub::search');
    }
}
