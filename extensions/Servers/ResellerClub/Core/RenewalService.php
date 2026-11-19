<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

class RenewalService
{
    /** @var callable(): int */
    private $clock;

    /** @param ?callable(): int $clock unix time, injectable for tests */
    public function __construct(
        private readonly RegistrarClient $client,
        private readonly OperationStore $store,
        private readonly int $toleranceDays = 3,
        private readonly int $windowDays = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? 'time';
    }

    public static function key(int $serviceId, int $newExpiresAt): string
    {
        return "renew:{$serviceId}:{$newExpiresAt}";
    }

    /**
     * Record the renewal intent. Returns the key if a new operation was created, null for a duplicate event.
     * The caller dispatches the job only when a key is returned.
     */
    public function record(int $serviceId, int $newExpiresAt, int $years): ?string
    {
        $key = self::key($serviceId, $newExpiresAt);
        [, $created] = $this->store->firstOrCreate($serviceId, 'renew', $key, [
            'new_expires_at' => $newExpiresAt,
            'years' => $years,
        ]);

        return $created ? $key : null;
    }

    /** Job body. Safe to run more than once for the same key. */
    public function run(string $key, int $orderId, bool $autoRenew = false): void
    {
        $op = $this->store->find($key) ?? throw new \LogicException("Unknown operation $key");
        if (in_array($op['status'], ['completed', 'submitted'], true)) {
            return;
        }

        $target = (int) $op['payload']['new_expires_at'];
        $years = max(1, (int) $op['payload']['years']);
        $this->store->update($key, ['attempts' => $op['attempts'] + 1, 'rc_order_id' => $orderId]);

        try {
            $live = $this->client->orderDetails($orderId); // never trust a stored expiry

            if ($live->expiresAt <= 0) {
                $this->fail($key, 'The registrar returned no expiry date for this order; renewal not attempted.');

                return;
            }
            if ($live->expiresAt >= $target - $this->toleranceDays * 86400) {
                $this->store->update($key, ['status' => 'completed']); // already renewed: no registrar call

                return;
            }
            // A paid renewal arrives shortly before expiry. A registrar term with a lot of time left means the
            // service date moved for another reason (refund re-renewal, admin edit): never spend funds on that.
            if ($live->expiresAt > ($this->clock)() + $this->windowDays * 86400) {
                $this->fail($key, "Registrar expiry is more than {$this->windowDays} days away; renewal not attempted (refund re-renewal or manual date change?). Retry once the domain is within the window.");

                return;
            }

            $res = $this->client->renew($orderId, $years, $live->expiresAt, $autoRenew);
            $this->store->update($key, ['status' => 'submitted', 'rc_action_id' => $res->actionId]);
        } catch (RegistrarException $e) {
            if ($e->isOutcomeUnknown()) {
                // The write may have landed: leave queued; the retry re-reads the live expiry first.
                $this->store->update($key, ['last_error' => $e->getMessage()]);
                throw $e;
            }
            $this->fail($key, $e->getMessage());
        }
    }

    private function fail(string $key, string $error): void
    {
        $this->store->update($key, ['status' => 'failed', 'last_error' => $error]);
    }
}
