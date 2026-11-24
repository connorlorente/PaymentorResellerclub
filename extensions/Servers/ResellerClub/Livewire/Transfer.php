<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Livewire;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Client area: transfer-out lock and the transfer (auth/EPP) code of a domain the customer owns. */
class Transfer extends Component
{
    public int $serviceId;

    public ?string $code = null;

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
        $orderId = (int) $service->properties()->where('key', 'rc_order_id')->value('value');
        if ($service->status !== Service::STATUS_ACTIVE || !$orderId) {
            throw new \RuntimeException('This domain cannot be managed right now.');
        }

        return [$service, ResellerClub::forService($service)->client(), $orderId];
    }

    private function say(string $message, bool $error = false): void
    {
        $this->message = $message;
        $this->error = $error;
    }

    private function limited(string $what, int $max): bool
    {
        $key = "rc-$what:" . $this->serviceId;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->say('Too many requests; please try again in a few minutes.', true);

            return true;
        }
        RateLimiter::hit($key, 600);

        return false;
    }

    public function showCode(): void
    {
        $this->code = null;
        if ($this->limited('authcode', 5)) {
            return;
        }
        try {
            [$service, $client, $orderId] = $this->context();
            $this->code = $client->authCode($orderId);
            try {
                Log::info('resellerclub: transfer code viewed', ['service' => $service->id, 'user' => Auth::id()]); // never the code itself
            } catch (\Throwable) {
                // an unwritable log must not stop the customer getting their code
            }
        } catch (RegistrarException $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
            $this->say('The registrar could not give the code right now. Please try again shortly, or contact support.', true);
        } catch (\RuntimeException $e) {
            $this->say($e->getMessage(), true);
        }
    }

    public function hideCode(): void
    {
        $this->code = null;
    }

    public function setLock(bool $locked): void
    {
        if ($this->limited('lock', 10)) {
            return;
        }
        try {
            [, $client, $orderId] = $this->context();
            $client->setTransferLock($orderId, $locked);
            $this->say($locked ? 'Transfer lock turned on.' : 'Transfer lock turned off. You can now transfer the domain away.');
        } catch (RegistrarException $e) {
            \Paymenter\Extensions\Servers\ResellerClub\Core\SafeReport::report($e);
            $this->say('The registrar could not change the lock. Please try again shortly.', true);
        } catch (\RuntimeException $e) {
            $this->say($e->getMessage(), true);
        }
    }

    public function render()
    {
        $locked = null;
        try {
            [, $client, $orderId] = $this->context();
            $locked = $client->transferLock($orderId);
        } catch (\Throwable $e) {
            // The page still works without knowing the lock state.
        }

        return view('resellerclub::transfer', ['locked' => $locked]);
    }
}
