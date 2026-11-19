<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Jobs;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Admin retry of a failed registration; the first attempt runs through Paymenter's CreateJob. */
class RegisterDomainJob implements ShouldQueue, ShouldBeUnique
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
        // A failure is recorded on the operation row, so the result is deliberately not thrown here.
        $r = ResellerClub::forService($service)->registerService(
            $service,
            ExtensionHelper::settingsToArray($service->product->settings),
            ExtensionHelper::getServiceProperties($service),
        );
        // createServer put a failed registration back to pending; a successful retry makes the service live again.
        if ($r['status'] !== 'failed' && $service->status === Service::STATUS_PENDING) {
            $service->forceFill(['status' => Service::STATUS_ACTIVE])->save();
        }
    }
}
