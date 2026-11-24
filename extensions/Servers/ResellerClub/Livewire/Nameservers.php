<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Livewire;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Paymenter\Extensions\Servers\ResellerClub\Core\NameserverService;
use Paymenter\Extensions\Servers\ResellerClub\Jobs\ChangeNameserversJob;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

class Nameservers extends Component
{
    public int $serviceId;

    public string $nameservers = '';

    public ?string $message = null;

    /** The nameservers every new domain starts with (from the server settings). */
    public array $defaults = [];

    public function mount(int $serviceId, array $current = [], array $defaults = []): void
    {
        $this->serviceId = $serviceId;
        $this->nameservers = implode("\n", $current);
        $this->defaults = $defaults;
    }

    public function useDefaults(): void
    {
        $this->nameservers = implode("\n", $this->defaults);
    }

    private function service(): Service
    {
        // Ownership is checked server side on every call; the order ID is read from properties, never the request.
        return Service::where('id', $this->serviceId)->where('user_id', Auth::id())->firstOrFail();
    }

    public function save(): void
    {
        $service = $this->service();
        $key = 'rc-ns:' . $service->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->message = 'Too many changes; try again in a few minutes.';

            return;
        }
        RateLimiter::hit($key, 300);

        $orderId = (int) $service->properties()->where('key', 'rc_order_id')->value('value');
        if (!$orderId || $service->status !== Service::STATUS_ACTIVE) {
            $this->message = 'This domain cannot be managed right now.';

            return;
        }
        try {
            $key = (new NameserverService(ResellerClub::forService($service)->client(), new EloquentOperationStore()))
                ->change($service->id, $orderId, preg_split('/[\s,]+/', trim($this->nameservers)));
            ChangeNameserversJob::dispatch($service->id, $key);
            $this->message = 'Submitted. It will show as complete once the registrar confirms.';
        } catch (\InvalidArgumentException $e) {
            $this->message = $e->getMessage();
        }
    }

    public function render()
    {
        $last = Operation::where('service_id', $this->serviceId)->where('type', 'nameservers')->latest('id')->first();

        return view('resellerclub::nameservers', ['status' => $last?->status]);
    }
}
