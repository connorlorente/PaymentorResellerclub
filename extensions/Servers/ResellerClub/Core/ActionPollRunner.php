<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use App\Models\Service;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Scheduled every five minutes from boot(): resolves submitted operations. */
class ActionPollRunner
{
    public function __invoke(): void
    {
        $store = new EloquentOperationStore();
        foreach (Operation::where('status', 'submitted')->whereNotNull('rc_action_id')->get() as $op) {
            $service = Service::find($op->service_id);
            if (!$service || !ResellerClub::ownsService($service)) {
                continue;
            }
            (new ActionPoller(ResellerClub::forService($service)->client(), $store))->poll($op->idempotency_key);
        }
    }
}
