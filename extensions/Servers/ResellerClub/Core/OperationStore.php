<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * Persistence for registrar operations. Eloquent-backed in Paymenter, in-memory in tests.
 * An operation is an array with: id, service_id, type, idempotency_key, rc_order_id, rc_action_id,
 * status (queued|submitted|completed|failed), attempts, last_error, payload.
 */
interface OperationStore
{
    /** Create the row unless the key exists. @return array{0: array, 1: bool} [operation, created] */
    public function firstOrCreate(int $serviceId, string $type, string $key, array $payload = []): array;

    public function update(string $key, array $changes): void;

    public function find(string $key): ?array;
}
