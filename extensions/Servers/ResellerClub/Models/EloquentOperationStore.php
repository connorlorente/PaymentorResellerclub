<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Models;

use Paymenter\Extensions\Servers\ResellerClub\Core\OperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Core\Redactor;

class EloquentOperationStore implements OperationStore
{
    public function firstOrCreate(int $serviceId, string $type, string $key, array $payload = []): array
    {
        $op = Operation::firstOrCreate(
            ['idempotency_key' => $key],
            ['service_id' => $serviceId, 'type' => $type, 'status' => 'queued', 'payload' => Redactor::payload($payload)],
        ); // unique index makes concurrent creation collapse into one row

        $created = $op->wasRecentlyCreated;
        if ($created) {
            // A just-created model only knows the attributes it was given: reload it so the columns that have
            // database defaults (attempts, rc_order_id, ...) are present, exactly as when the row is read later.
            $op->refresh();
        }

        return [$op->toArray(), $created];
    }

    public function update(string $key, array $changes): void
    {
        if (isset($changes['last_error'])) {
            $changes['last_error'] = Redactor::text($changes['last_error']);
        }
        if (isset($changes['payload']) && is_array($changes['payload'])) {
            // A bulk update bypasses the model's array cast.
            $changes['payload'] = json_encode(Redactor::payload($changes['payload']));
        }
        Operation::where('idempotency_key', $key)->update($changes);
    }

    public function find(string $key): ?array
    {
        return Operation::where('idempotency_key', $key)->first()?->toArray();
    }
}
