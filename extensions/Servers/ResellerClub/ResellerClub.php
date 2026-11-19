<?php

namespace Paymenter\Extensions\Servers\ResellerClub;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Service\Updated;
use App\Helpers\ExtensionHelper;
use App\Models\Server as ServerModel;
use App\Models\Service;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\ResellerClub\Core\ActionPollRunner;
use Paymenter\Extensions\Servers\ResellerClub\Console\CreateTldProducts;
use Paymenter\Extensions\Servers\ResellerClub\Core\ExpirySyncRunner;
use Paymenter\Extensions\Servers\ResellerClub\Core\PricingSyncRunner;
use Paymenter\Extensions\Servers\ResellerClub\Core\RegistrationService;
use Paymenter\Extensions\Servers\ResellerClub\Livewire\Nameservers;
use Paymenter\Extensions\Servers\ResellerClub\Livewire\Search;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainAvailable;
use Paymenter\Extensions\Servers\ResellerClub\Rules\RegistrationsOpen;
use Paymenter\Extensions\Servers\ResellerClub\Jobs\RegisterDomainJob;
use Paymenter\Extensions\Servers\ResellerClub\Listeners\RenewOnServiceUpdated;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;
use Paymenter\Extensions\Servers\ResellerClub\Policies\OperationPolicy;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

#[ExtensionMeta(
    name: 'ResellerClub Domains',
    description: 'Sell and manage ResellerClub domains',
    version: '0.1.0',
    author: 'Local',
)]
class ResellerClub extends Server
{
    private const PATH = 'extensions/Servers/ResellerClub';

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'reseller_id', 'label' => 'Reseller ID (auth-userid)', 'type' => 'text', 'required' => true],
            ['name' => 'api_key', 'label' => 'API key', 'type' => 'text', 'required' => true, 'encrypted' => true],
            ['name' => 'live', 'label' => 'Live mode (off = demo account on the test host)', 'type' => 'checkbox'],
            ['name' => 'live_base', 'label' => 'Live API base URL', 'type' => 'text', 'default' => LegacyHttpClient::LIVE_BASE],
            ['name' => 'allow_registrations', 'label' => 'Allow new registrations', 'type' => 'checkbox', 'default' => true],
            ['name' => 'default_ns1', 'label' => 'Default nameserver 1', 'type' => 'text'],
            ['name' => 'default_ns2', 'label' => 'Default nameserver 2', 'type' => 'text'],
            ['name' => 'renew_tolerance_days', 'label' => 'Renewal guard tolerance (days)', 'type' => 'number', 'default' => 3],
            ['name' => 'renew_window_days', 'label' => 'Renewal window (days): skip registrar renewals when the domain has more time left than this', 'type' => 'number', 'default' => 60],
            ['name' => 'markup_type', 'label' => 'Default markup type', 'type' => 'select', 'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount'], 'default' => 'percent'],
            ['name' => 'markup_value', 'label' => 'Default markup (percent, or amount in the store currency)', 'type' => 'number', 'default' => 20],
            ['name' => 'fx_rate', 'label' => 'Exchange rate (reseller currency to store currency)', 'type' => 'number', 'default' => 1],
            ['name' => 'auto_apply_prices', 'label' => 'Auto-apply nightly price changes (off = review proposals first)', 'type' => 'checkbox', 'default' => false],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->client()->ping();

            return true;
        } catch (RegistrarException $e) {
            return match ($e->kind) {
                RegistrarException::AUTH => 'Credentials rejected: check the reseller ID and API key.',
                RegistrarException::IP_NOT_WHITELISTED => 'This server\'s IP is not whitelisted: add it at Settings > API in ResellerClub.',
                RegistrarException::UNREACHABLE => 'Could not reach ResellerClub: ' . $e->getMessage(),
                default => $e->getMessage(),
            };
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'tld', 'label' => 'TLD (without dot)', 'type' => 'text', 'required' => true],
            ['name' => 'years', 'label' => 'Registration years', 'type' => 'number', 'default' => 1],
            ['name' => 'privacy', 'label' => 'Buy privacy protection by default', 'type' => 'checkbox'],
            ['name' => 'attributes', 'label' => 'TLD attributes (name=value per line)', 'type' => 'textarea'],
            ['name' => 'markup_type', 'label' => 'Markup type override', 'type' => 'select', 'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount']],
            ['name' => 'markup_value', 'label' => 'Markup override (blank = server default)', 'type' => 'number'],
        ];
    }

    /** Off unless explicitly enabled: price changes are proposed, not applied. */
    public function autoApplyPrices(): bool
    {
        return filter_var($this->config('auto_apply_prices'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Unset means on: the switch defaults to allowing registrations. */
    public function registrationsAllowed(): bool
    {
        $v = $this->config('allow_registrations');

        return $v === null || filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function getCheckoutConfig($product, $values = [], $settings = []): array
    {
        $fields = [];
        $text = fn ($n, $l, $req = true) => ['name' => $n, 'label' => $l, 'type' => 'text', 'required' => $req];
        // The rule rejects the order at checkout while registrations are off.
        // 'bail' stops at the first failure, so no availability call is made while registrations are off.
        $rules = ['bail', 'required', new RegistrationsOpen($this->registrationsAllowed())];
        if (!empty($settings['tld'])) {
            $rules[] = new DomainAvailable($this->availability(), (string) $settings['tld']); // re-checks at submit
        }
        $fields[] = $text('domain_name', 'Domain name') + ['validation' => $rules];
        foreach ([['registrant_name', 'Registrant name'], ['registrant_email', 'Registrant email'], ['registrant_phone_cc', 'Phone country code'],
            ['registrant_phone', 'Phone'], ['registrant_address', 'Address'], ['registrant_city', 'City'], ['registrant_state', 'State/region'],
            ['registrant_country', 'Country (ISO)'], ['registrant_zip', 'Postal code']] as [$n, $l]) {
            $fields[] = $text($n, $l);
        }
        $fields[] = $text('registrant_company', 'Company', false);
        $fields[] = $text('nameservers', 'Nameservers (comma separated, blank = default)', false);

        return $fields;
    }

    /** First paid invoice: queue-safe registration. Failure leaves the service pending with a failed operation. */
    public function createServer(Service $service, $settings, $properties)
    {
        try {
            $r = $this->registerService($service, $settings, $properties);
        } catch (RegistrarException $e) {
            // Outcome unknown (timeout): hand over to a delayed job, which looks the domain up before it retries.
            $this->markPending($service);
            RegisterDomainJob::dispatch($service->id, RegistrationService::key($service->id))->delay(now()->addMinutes(5));

            throw $e;
        }
        if ($r['status'] === 'failed') {
            // Paymenter sets the service active right after queueing this job, so put it back to pending.
            $this->markPending($service);

            throw new \RuntimeException('Domain registration failed: ' . $r['error']); // op visible to admins
        }

        return true;
    }

    private function markPending(Service $service): void
    {
        $service->forceFill(['status' => Service::STATUS_PENDING])->save();
    }

    /** Shared by createServer and the admin retry job. Records the outcome on the operation and stores the registrar IDs. */
    public function registerService(Service $service, $settings, $properties): array
    {
        $store = new EloquentOperationStore();
        $svc = new RegistrationService($this->client(), $store, $this->registrationsAllowed());
        $p =fn ($k) => $properties[$k] ?? null;

        $ns = array_filter(array_map('trim', explode(',', (string) $p('nameservers'))));
        $ns = $ns ?: array_filter([$this->config('default_ns1'), $this->config('default_ns2')]);

        $contact = [
            'name' => $p('registrant_name'), 'email' => $p('registrant_email'), 'company' => $p('registrant_company'),
            'address' => $p('registrant_address'), 'city' => $p('registrant_city'), 'state' => $p('registrant_state'),
            'country' => $p('registrant_country'), 'zip' => $p('registrant_zip'),
            'phone_cc' => $p('registrant_phone_cc'), 'phone' => $p('registrant_phone'),
        ];
        $existing = (int) $service->user->properties()->where('key', 'rc_customer_id')->value('value') ?: null;

        // A yearly plan decides the term; the product's "years" setting is the fallback for other plans.
        $plan = $service->plan;
        $years = $plan && $plan->billing_unit === 'year' ? (int) $plan->billing_period : (int) ($settings['years'] ?? 1);

        $r = $svc->register($service->id, (string) $p('domain_name'), max(1, $years), array_values($ns), $contact, $contact,
            ['privacy' => (bool) ($settings['privacy'] ?? false), 'attributes' => $this->parseAttributes($settings['attributes'] ?? '')], $existing);

        if ($r['status'] !== 'failed') {
            $set = fn ($model, $k, $v) => $v && $model->properties()->updateOrCreate(['key' => $k], ['name' => $k, 'value' => $v]);
            $set($service, 'rc_order_id', $r['order_id']);
            $set($service, 'rc_contact_id', $r['contact_id'] ?? null);
            $set($service->user, 'rc_customer_id', $r['customer_id'] ?? null);
        }

        return $r;
    }

    // The registrar is never touched on suspend/terminate: the cron job must not delete a domain.
    public function suspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        throw new \Exception('Upgrades are not supported for domains.');
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        if (!($properties['rc_order_id'] ?? null)) {
            return [];
        }

        return [['type' => 'view', 'name' => 'nameservers', 'label' => 'Nameservers', 'function' => 'getNameserversView']];
    }

    public function getNameserversView(Service $service, $settings, $properties, $view)
    {
        $current = [];
        try {
            $current = $this->client()->orderDetails((int) $properties['rc_order_id'])->nameservers;
        } catch (RegistrarException) {
            // Registrar outage must not blank the page.
        }

        return view('resellerclub::nameservers-wrapper', ['serviceId' => $service->id, 'current' => $current]);
    }

    public function boot(): void
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('resellerclub', __DIR__ . '/resources/views');
        Livewire::component('resellerclub.nameservers', Nameservers::class);
        Livewire::component('resellerclub.search', Search::class);
        Event::listen(Updated::class, RenewOnServiceUpdated::class);
        Gate::policy(Operation::class, OperationPolicy::class);
        Event::listen('permissions', fn () => [
            'admin.resellerclub.operations.view' => 'View ResellerClub domain operations',
            'admin.resellerclub.operations.retry' => 'Retry failed ResellerClub domain operations',
            'admin.resellerclub.manage' => 'Create ResellerClub TLD products and apply proposed prices',
        ]);
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(fn ($artisan) => $artisan->resolve(CreateTldProducts::class));
        }
        Schedule::call(new ActionPollRunner())->name('resellerclub-poll-actions')->everyFiveMinutes()->onOneServer();
        Schedule::call(new ExpirySyncRunner())->name('resellerclub-sync-expiry')->daily()->onOneServer();
        Schedule::call(new PricingSyncRunner())->name('resellerclub-sync-pricing')->dailyAt('03:00')->onOneServer();
    }

    public function installed(): void
    {
        ExtensionHelper::runMigrations(self::PATH . '/database/migrations');
    }

    public function upgraded($oldVersion = null): void
    {
        $this->installed();
    }

    public function uninstalled(): void
    {
        ExtensionHelper::rollbackMigrations(self::PATH . '/database/migrations');
    }

    public function client(): RegistrarClient
    {
        return new LegacyHttpClient(
            (string) $this->config('reseller_id'),
            (string) $this->config('api_key'),
            (bool) $this->config('live'),
            null,
            (string) ($this->config('live_base') ?: LegacyHttpClient::LIVE_BASE),
        );
    }

    public function availability(): AvailabilityChecker
    {
        return new AvailabilityChecker(
            $this->client(),
            fn (string $key) => Cache::get($key),
            fn (string $key, string $value, int $ttl) => Cache::put($key, $value, $ttl),
        );
    }

    public static function ownsService(Service $service): bool
    {
        return $service->product?->server?->extension === 'ResellerClub';
    }

    public static function forService(Service $service): static
    {
        return static::forServer($service->product->server);
    }

    public static function forServer(ServerModel $server): static
    {
        return ExtensionHelper::getExtension('server', $server->extension, $server->settings);
    }

    private function parseAttributes(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
