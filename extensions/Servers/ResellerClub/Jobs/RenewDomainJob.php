<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Jobs;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\ResellerClub\Core\RenewalService;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

class RenewDomainJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $serviceId, public string $key) {}

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $service = Service::findOrFail($this->serviceId);
        $orderId = (int) $service->properties()->where('key', 'rc_order_id')->value('value');
        if (!$orderId) {
            return; // not registered at the registrar; reconciliation reports this
        }
        $ext = ResellerClub::forService($service);
        (new RenewalService($ext->client(), new EloquentOperationStore(), (int) ($ext->config('renew_tolerance_days') ?: 3), (int) ($ext->config('renew_window_days') ?: 60)))
            ->run($this->key, $orderId);
    }
}
