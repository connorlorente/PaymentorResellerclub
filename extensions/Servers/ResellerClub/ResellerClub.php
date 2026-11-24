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
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Servers\ResellerClub\Core\CancellationService;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use App\Events\CartItem\Created as CartItemCreated;
use Paymenter\Extensions\Servers\ResellerClub\Core\AccountContact;
use Paymenter\Extensions\Servers\ResellerClub\Core\ActionPollRunner;
use Paymenter\Extensions\Servers\ResellerClub\Listeners\AddDomainToCart;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainOrderable;
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
    version: '0.3.4',
    author: 'Virtual Developments',
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
            ['name' => 'allow_transfers', 'label' => 'Allow transfers in', 'type' => 'checkbox', 'default' => true],
            ['name' => 'sell_hidden_products', 'label' => 'Testing: offer hidden domain products at hosting checkout (keep off in production)', 'type' => 'checkbox', 'default' => false],
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

    /** Unset means on. */
    public function transfersAllowed(): bool
    {
        $v = $this->config('allow_transfers');

        return $v === null || filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Registrant details come from the customer's account (WHMCS style), so checkout only asks
     * for the name, register-or-transfer, and the auth code for a transfer.
     */
    public function getCheckoutConfig($product, $values = [], $settings = []): array
    {
        $tld = strtolower(ltrim((string) ($settings['tld'] ?? ''), '.'));
        $options = [DomainOrderable::REGISTER => 'Register a new domain'];
        if ($this->transfersAllowed()) {
            $options[DomainOrderable::TRANSFER] = 'Transfer my domain from another provider';
        }
        $action = DomainOrderable::option(['checkoutConfig' => (array) $values], 'domain_action');
        if (!isset($options[$action])) {
            $action = DomainOrderable::REGISTER;
        }

        $fields = [];
        if (count($options) > 1) {
            $fields[] = ['name' => 'domain_action', 'label' => 'What would you like to do?', 'type' => 'radio', 'options' => $options,
                'default' => DomainOrderable::REGISTER, 'required' => true, 'validation' => ['in:' . implode(',', array_keys($options))]];
        }
        // 'bail' stops at the first failure, so no availability call is made while registrations are off.
        $fields[] = ['name' => 'domain_name', 'label' => 'Domain name', 'type' => 'text', 'required' => true, 'placeholder' => $tld ? "example.$tld" : 'example.co.uk',
            'validation' => ['bail', 'required', new RegistrationsOpen($this->registrationsAllowed()),
                new DomainOrderable($tld ? $this->availability() : null, $tld ? [$tld] : [], 'domain_action')]];
        if ($action === DomainOrderable::TRANSFER) {
            $fields[] = ['name' => 'auth_code', 'label' => 'Transfer (EPP/auth) code from your current provider', 'type' => 'text', 'required' => true,
                'validation' => ['required', 'string', 'max:255']];
        }
        // No optional fields: Paymenter saves each checkout field as a service property in a NOT NULL column, so a
        // blank optional field (stored as null) would make the whole order fail. New domains use the default
        // nameservers from the server settings; customers change them afterwards under "Nameservers".
        return $fields;
    }

    /**
     * TLDs (lowercase, no dot) that have a visible, priced domain product on a ResellerClub server.
     *
     * @return array<string,\App\Models\Product> tld => product
     */
    public static function sellableProducts(): array
    {
        $out = [];
        $servers = ServerModel::where('extension', 'ResellerClub')->get();
        $includeHidden = $servers->contains(fn ($s) => filter_var(static::forServer($s)->config('sell_hidden_products'), FILTER_VALIDATE_BOOLEAN));
        $query = \App\Models\Product::whereIn('server_id', $servers->pluck('id'))->with(['settings', 'category', 'plans.prices']);
        if (!$includeHidden) {
            $query->where('hidden', false);
        }
        foreach ($query->get() as $product) {
            $tld = strtolower(ltrim((string) (ExtensionHelper::settingsToArray($product->settings)['tld'] ?? ''), '.'));
            if ($tld !== '' && $product->price()->available && !isset($out[$tld])) {
                $out[$tld] = $product;
            }
        }

        return $out;
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

            $this->alertAdmin("Domain registration failed (service {$service->id})", "A domain order could not be registered: service {$service->id}.\n{$r['error']}\nFix the cause (e.g. top up the ResellerClub balance), then use Retry in Admin > Domain operations.");

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
        $isTransfer = ($properties['domain_action'] ?? DomainOrderable::REGISTER) === DomainOrderable::TRANSFER;
        $allowed = $this->registrationsAllowed() && (!$isTransfer || $this->transfersAllowed());
        $svc = new RegistrationService($this->client(), $store, $allowed);
        $p = fn ($k) => $properties[$k] ?? null;

        $ns = array_filter(array_map('trim', explode(',', (string) $p('nameservers'))));
        $ns = $ns ?: array_filter([$this->config('default_ns1'), $this->config('default_ns2')]);

        // Registrant = the customer's account details. Older orders may still carry registrant_* checkout fields.
        $user = $service->user;
        $props = $user->properties()->pluck('value', 'key')->all();
        ['contact' => $contact, 'missing' => $missing] = AccountContact::build(
            (string) ($p('registrant_name') ?? $user->name), (string) ($p('registrant_email') ?? $user->email), $props,
        );
        if ($missing) {
            [$op] = $store->firstOrCreate($service->id, $isTransfer ? 'transfer' : 'register', RegistrationService::key($service->id), ['domain' => (string) $p('domain_name')]);
            if (!in_array($op['status'], ['submitted', 'completed'], true)) {
                $error = 'Customer account is missing: ' . implode(', ', $missing) . '. Complete the account details, then retry this operation.';
                $store->update(RegistrationService::key($service->id), ['status' => 'failed', 'last_error' => $error]);

                return ['status' => 'failed', 'order_id' => null, 'error' => $error];
            }
        }
        $existing = (int) $user->properties()->where('key', 'rc_customer_id')->value('value') ?: null;

        // A yearly plan decides the term; the product's "years" setting is the fallback for other plans.
        $plan = $service->plan;
        $years = $plan && $plan->billing_unit === 'year' ? (int) $plan->billing_period : (int) ($settings['years'] ?? 1);

        $r = $svc->register($service->id, (string) $p('domain_name'), max(1, $years), array_values($ns), $contact, $contact,
            ['privacy' => (bool) ($settings['privacy'] ?? false), 'attributes' => $this->parseAttributes($settings['attributes'] ?? ''),
                'transfer' => $isTransfer, 'auth_code' => (string) $p('auth_code')], $existing);

        if ($r['status'] !== 'failed') {
            $set = fn ($model, $k, $v) => $v && $model->properties()->updateOrCreate(['key' => $k], ['name' => $k, 'value' => $v]);
            $set($service, 'rc_order_id', $r['order_id']);
            $set($service, 'rc_contact_id', $r['contact_id'] ?? null);
            $set($service->user, 'rc_customer_id', $r['customer_id'] ?? null);
            if ($isTransfer) {
                // The registrar has the auth code now; don't keep a secret that can move the domain.
                $service->properties()->where('key', 'auth_code')->delete();
            }
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

    /**
     * A cancelled domain tells the registrar (deleted when still inside the grace period, otherwise left to expire).
     * This also runs inside the nightly cron's transaction, so nothing here may throw.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        try {
            $orderId = (int) (((array) $properties)['rc_order_id'] ?? 0);
            if ($orderId) {
                $outcome = (new CancellationService($this->client(), new EloquentOperationStore()))->cancel($service->id, $orderId);
                if ($outcome === 'failed') {
                    $this->alertAdmin("Domain cancellation needs attention (service {$service->id})", "The registrar could not be told about the cancellation of service {$service->id}. See Admin > Domain operations.");
                }
            }
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }

        return true;
    }

    /** Plain email to the system address: a customer paid for something that now needs a human. Never throws. */
    private function alertAdmin(string $subject, string $body): void
    {
        try {
            $to = config('settings.system_email_address');
            if ($to) {
                Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
            }
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }
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

        return [
            ['type' => 'view', 'name' => 'nameservers', 'label' => 'Nameservers', 'function' => 'getNameserversView'],
            ['type' => 'view', 'name' => 'transfer', 'label' => 'Transfer away', 'function' => 'getTransferView'],
        ];
    }

    public function getTransferView(Service $service, $settings, $properties, $view)
    {
        return view('resellerclub::transfer-wrapper', ['serviceId' => $service->id]);
    }

    public function getNameserversView(Service $service, $settings, $properties, $view)
    {
        $current = [];
        try {
            $current = $this->client()->orderDetails((int) $properties['rc_order_id'])->nameservers;
        } catch (RegistrarException) {
            // Registrar outage must not blank the page.
        }

        $defaults = array_values(array_filter([$this->config('default_ns1'), $this->config('default_ns2')]));

        return view('resellerclub::nameservers-wrapper', ['serviceId' => $service->id, 'current' => $current, 'defaults' => $defaults]);
    }

    private function registerHooks(): void
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('resellerclub', __DIR__ . '/resources/views');
        Livewire::component('resellerclub.nameservers', Nameservers::class);
        Livewire::component('resellerclub.search', Search::class);
        Livewire::component('resellerclub.transfer', \Paymenter\Extensions\Servers\ResellerClub\Livewire\Transfer::class);
        Event::listen(Updated::class, RenewOnServiceUpdated::class);
        Event::listen('pages.home', \Paymenter\Extensions\Servers\ResellerClub\Listeners\HomeDomainSearch::class);
        // Hosting ordered with "register/transfer a domain": put the matching domain product in the cart too.
        Event::listen(CartItemCreated::class, AddDomainToCart::class);
        // An edited cart item has its config options rebuilt by Paymenter: keep the "Domain" line on it.
        Event::listen(\App\Events\CartItem\Updated::class, [AddDomainToCart::class, 'handleUpdated']);
        Gate::policy(Operation::class, OperationPolicy::class);
        Event::listen('permissions', fn () => [
            'admin.resellerclub.operations.view' => 'View ResellerClub domain operations',
            'admin.resellerclub.operations.retry' => 'Retry failed ResellerClub domain operations',
            'admin.resellerclub.manage' => 'Create ResellerClub TLD products and apply proposed prices',
        ]);
        if (app()->runningInConsole()) {
            // Runs at the start of every artisan command (including the nightly cron), so it must never throw.
            ConsoleApplication::starting(function ($artisan) {
                try {
                    $artisan->resolve(CreateTldProducts::class);
                } catch (\Throwable $e) {
                    \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
                }
            });
        }
        // Nothing here may ever break Paymenter's own scheduled work: every task swallows and reports its errors,
        // and onOneServer() is not used (it needs a cache lock and this is a single server).
        Schedule::call(self::safely(new ActionPollRunner()))->name('resellerclub-poll-actions')->everyFiveMinutes();
        Schedule::call(self::safely(new ExpirySyncRunner()))->name('resellerclub-sync-expiry')->daily();
        Schedule::call(self::safely(new PricingSyncRunner()))->name('resellerclub-sync-pricing')->dailyAt('03:00');
    }

    /** Wraps a scheduled task so that any failure is reported but never propagates to the scheduler. */
    private static function safely(callable $task): \Closure
    {
        return function () use ($task): void {
            try {
                $task();
            } catch (\Throwable $e) {
                \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
            }
        };
    }

    /**
     * Paymenter boots every server extension on every request and only catches \Exception there,
     * so a PHP \Error in here would take the whole billing site down. Contain it.
     */
    public function boot(): void
    {
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
        }
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
