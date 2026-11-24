<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Livewire;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldRules;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Client area: WHOIS privacy on or off for a domain that was bought with privacy. */
class Privacy extends Component
{
    public int $serviceId;

    public ?string $message = null;

    public bool $error = false;

    public function mount(int $serviceId): void
    {
        $this->serviceId = $serviceId;
    }

    /** Ownership is checked server side on every call; the order id never comes from the request. */
    private function context(): array
    {
        $service = Service::where('id', $this->serviceId)->where('user_id', Auth::id())->firstOrFail();
        $props = $service->properties()->pluck('value', 'key')->all();
        $orderId = (int) ($props['rc_order_id'] ?? 0);
        if ($service->status !== Service::STATUS_ACTIVE || !$orderId) {
            throw new \RuntimeException('This domain cannot be managed right now.');
        }

        return [ResellerClub::forService($service)->client(), $orderId, (string) ($props['domain_name'] ?? '')];
    }

    private function say(string $message, bool $error = false): void
    {
        $this->message = $message;
        $this->error = $error;
    }

    public function set(bool $protect): void
    {
        $key = 'rc-privacy:' . $this->serviceId;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->say('Too many changes; please try again in a few minutes.', true);

            return;
        }
        RateLimiter::hit($key, 600);

        try {
            [$client, $orderId] = $this->context();
            $client->setPrivacy($orderId, $protect);
            $this->say($protect ? 'WHOIS privacy turned on.' : 'WHOIS privacy turned off. Your contact details may now be public.');
        } catch (RegistrarException $e) {
            SafeReport::report($e);
            $this->say('The registrar could not change this. WHOIS privacy can only be switched for domains bought with privacy.', true);
        } catch (\RuntimeException $e) {
            $this->say($e->getMessage(), true);
        }
    }

    public function render()
    {
        $allowed = null;
        $protected = null;
        $supported = true;
        try {
            [$client, $orderId, $domain] = $this->context();
            $supported = TldRules::supportsPrivacy($domain);
            if ($supported) {
                $d = $client->orderDetails($orderId);
                $allowed = $d->privacyAllowed;
                $protected = $d->privacyProtected;
            }
        } catch (\Throwable $e) {
            // The page still works without knowing the state.
        }

        return view('resellerclub::privacy', ['supported' => $supported && $allowed !== false, 'protected' => $protected]);
    }
}
