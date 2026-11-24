<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

/**
 * A cancelled domain service tells the registrar. Registrations are only deletable inside the registry's grace
 * period; after that the registrar refuses, and the domain is simply left to expire (it is never auto-renewed at the
 * registrar, and Paymenter no longer renews it). Either way the result is written to the operation log.
 */
class CancellationService
{
    public function __construct(private readonly RegistrarClient $client, private readonly OperationStore $store)
    {
    }

    /** @return string completed|failed */
    public function cancel(int $serviceId, int $orderId): string
    {
        $key = 'cancel:' . $serviceId;
        [$op] = $this->store->firstOrCreate($serviceId, 'cancel', $key, ['rc_order_id' => $orderId]);
        if (in_array($op['status'], ['completed', 'submitted'], true)) {
            return $op['status'] === 'completed' ? 'completed' : 'submitted';
        }
        $this->store->update($key, ['attempts' => (int) ($op['attempts'] ?? 0) + 1, 'rc_order_id' => $orderId]);

        try {
            $res = $this->client->deleteOrder($orderId);
            $this->store->update($key, ['status' => 'submitted', 'rc_action_id' => $res->actionId, 'last_error' => null]);

            return 'submitted';
        } catch (RegistrarException $e) {
            $refused = $e->kind === RegistrarException::API; // the registrar answered "no": not deletable any more
            $this->store->update($key, [
                'status' => $refused ? 'completed' : 'failed',
                'last_error' => ($refused ? 'Not deleted at the registrar (outside its grace period); the domain will expire and is not renewed. ' : '') . $e->getMessage(),
            ]);

            return $refused ? 'completed' : 'failed';
        }
    }
}
