<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;

class ActionPoller
{
    public function __construct(private readonly RegistrarClient $client, private readonly OperationStore $store, private readonly int $maxAttempts = 24) {}

    /** Resolve one submitted operation. Returns the resulting status. */
    public function poll(string $key): string
    {
        $op = $this->store->find($key);
        if (!$op || $op['status'] !== 'submitted' || !$op['rc_action_id']) {
            return $op['status'] ?? 'missing';
        }
        $status = $this->client->actionStatus((int) $op['rc_action_id']);
        if ($status->isCompleted()) {
            $this->store->update($key, ['status' => 'completed']);

            return 'completed';
        }
        $attempts = $op['attempts'] + 1;
        if ($status->isFailed() || $attempts >= $this->maxAttempts) {
            $this->store->update($key, ['status' => 'failed', 'attempts' => $attempts,
                'last_error' => $status->isFailed() ? $status->description
                    : "Action did not complete in time (registrar status: {$status->status}, {$status->description})."]);

            return 'failed';
        }
        $this->store->update($key, ['attempts' => $attempts]);

        return 'submitted';
    }
}
