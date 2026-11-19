<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Listeners;

use App\Events\Service\Updated;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\ResellerClub\Core\RenewalService;
use Paymenter\Extensions\Servers\ResellerClub\Jobs\RenewDomainJob;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Paymenter has no renewal hook, so a forward change of expires_at is the renewal signal. */
class RenewOnServiceUpdated
{
    public function handle(Updated $event): void
    {
        $service = $event->service;

        if (!ResellerClub::ownsService($service) || !$service->wasChanged('expires_at')) {
            return;
        }
        // The first payment moves a pending service to active; that is registration, not renewal.
        if ($service->getOriginal('status') === Service::STATUS_PENDING) {
            return;
        }
        $old = $service->getOriginal('expires_at');
        $new = $service->expires_at;
        if (!$old || !$new) {
            return;
        }
        $oldTs = Carbon::parse($old)->timestamp;
        if ($new->timestamp <= $oldTs) {
            return; // not a forward move
        }

        // The registrar only renews in whole years; a month-based plan cannot be mirrored there.
        $plan = $service->plan;
        if (!$plan || $plan->billing_unit !== 'year') {
            Log::warning('ResellerClub: renewal skipped, the plan is not yearly', ['service_id' => $service->id]);

            return;
        }
        $years = max(1, (int) $plan->billing_period);
        $svc = new RenewalService(ResellerClub::forService($service)->client(), new EloquentOperationStore());
        if ($key = $svc->record($service->id, $new->timestamp, $years)) {
            RenewDomainJob::dispatch($service->id, $key);
        }
    }
}
