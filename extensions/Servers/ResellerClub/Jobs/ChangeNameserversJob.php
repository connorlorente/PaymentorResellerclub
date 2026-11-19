<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Jobs;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\ResellerClub\Core\NameserverService;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

class ChangeNameserversJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $serviceId, public string $key) {}

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $service = Service::findOrFail($this->serviceId);
        $orderId = (int) $service->properties()->where('key', 'rc_order_id')->value('value');
        if (!$orderId) {
            return;
        }
        (new NameserverService(ResellerClub::forService($service)->client(), new EloquentOperationStore()))
            ->run($this->key, $orderId);
    }
}
