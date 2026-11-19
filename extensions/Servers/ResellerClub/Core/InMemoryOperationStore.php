<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

class InMemoryOperationStore implements OperationStore
{
    /** @var array<string,array> */
    public array $rows = [];

    public function firstOrCreate(int $serviceId, string $type, string $key, array $payload = []): array
    {
        if (isset($this->rows[$key])) {
            return [$this->rows[$key], false];
        }

        return [$this->rows[$key] = [
            'id' => count($this->rows) + 1,
            'service_id' => $serviceId,
            'type' => $type,
            'idempotency_key' => $key,
            'rc_order_id' => null,
            'rc_action_id' => null,
            'status' => 'queued',
            'attempts' => 0,
            'last_error' => null,
            'payload' => Redactor::payload($payload),
        ], true];
    }

    public function update(string $key, array $changes): void
    {
        if (isset($changes['last_error'])) {
            $changes['last_error'] = Redactor::text($changes['last_error']);
        }
        $this->rows[$key] = array_merge($this->rows[$key], $changes);
    }

    public function find(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }
}
