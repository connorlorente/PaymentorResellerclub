<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\ResellerClub\Models\EloquentOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/** Scheduled daily from boot(): records registrar expiry on each domain service and reports differences. */
class ExpirySyncRunner
{
    public function __invoke(): void
    {
        foreach (Server::where('extension', 'ResellerClub')->get() as $server) {
            $ext = ResellerClub::forServer($server);
            $local = [];
            $services = Service::whereHas('product', fn ($q) => $q->where('server_id', $server->id))
                ->whereHas('properties', fn ($q) => $q->where('key', 'rc_order_id'))
                ->with('properties')->get();
            foreach ($services as $service) {
                $orderId = (int) $service->properties->firstWhere('key', 'rc_order_id')?->value;
                if (!$orderId || !$service->expires_at) {
                    continue;
                }
                $local[$orderId] = [
                    'service_id' => $service->id,
                    'expires_at' => $service->expires_at->timestamp,
                    'renew_key' => Operation::where('service_id', $service->id)->where('type', 'renew')
                        ->where('status', 'completed')->latest('id')->value('idempotency_key'), // an in-flight renewal is not a failure
                ];
            }
            if (!$local) {
                continue;
            }

            $report = (new ExpirySync($ext->client(), new EloquentOperationStore(), (int) ($ext->config('renew_tolerance_days') ?: 3)))
                ->run($local, fn (int $serviceId, int $expiry) => Service::find($serviceId)?->properties()
                    ->updateOrCreate(['key' => 'rc_expiry'], ['name' => 'rc_expiry', 'value' => $expiry]));

            foreach ($report as $row) {
                $level = $row['outcome'] === ExpirySync::REGISTRAR_LATER ? 'info' : 'warning';
                Log::$level('ResellerClub expiry differs', $row);
            }
        }
    }
}
