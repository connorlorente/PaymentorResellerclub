<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use App\Models\Service;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/**
 * Scheduled every five minutes from boot(): resolves submitted operations, and keeps watching registrations that
 * are held at the registrar because the reseller balance cannot pay for them.
 */
class ActionPollRunner
{
    public function __invoke(): void
    {
        $store = new EloquentOperationStore();

        $ops = Operation::where(function ($q) {
            $q->where('status', 'submitted')->whereNotNull('rc_action_id');
        })->orWhere(function ($q) {
            $q->whereIn('type', RegistrationWatcher::WATCHED_TYPES)->whereIn('status', RegistrationWatcher::WATCHED_STATUSES);
        })->get();

        foreach ($ops as $op) {
            $service = Service::find($op->service_id);
            if (!$service || !ResellerClub::ownsService($service)) {
                continue;
            }
            try {
                $ext = ResellerClub::forService($service);
                $client = $ext->client();
                $status = $op->status;

                if (in_array($op->type, RegistrationWatcher::WATCHED_TYPES, true)) {
                    $watcher = new RegistrationWatcher(
                        $client, $store,
                        fn (array $row) => $ext->registrationHeld($service, $row),
                        fn (array $row, int $expires) => $ext->registrationActive($service, $expires),
                    );
                    $status = $watcher->check($op->idempotency_key);
                }

                if ($status === 'submitted' && $op->rc_action_id) {
                    $status = (new ActionPoller($client, $store))->poll($op->idempotency_key);
                    if ($status === 'failed' && in_array($op->type, RegistrationWatcher::WATCHED_TYPES, true)) {
                        $ext->registrationFailed($service, (string) ($store->find($op->idempotency_key)['last_error'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                SafeReport::report($e); // one bad row must not stop the others
            }
        }
    }
}
