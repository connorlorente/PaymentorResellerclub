<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

class NameserverService
{
    public function __construct(private readonly RegistrarClient $client, private readonly OperationStore $store) {}

    /** @return string[] normalised hostnames; throws InvalidArgumentException */
    public static function validate(array $ns): array
    {
        $ns = array_values(array_unique(array_map(fn ($h) => strtolower(trim((string) $h)), array_filter($ns, fn ($h) => trim((string) $h) !== ''))));
        if (count($ns) < 2 || count($ns) > 13) {
            throw new \InvalidArgumentException('Provide between 2 and 13 nameservers.');
        }
        foreach ($ns as $h) {
            if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h)) {
                throw new \InvalidArgumentException("Invalid nameserver hostname: $h");
            }
        }

        return $ns;
    }

    /** Records the request; the registrar write happens in run(), from a queued job. @return string operation key */
    public function change(int $serviceId, int $orderId, array $ns): string
    {
        $ns = self::validate($ns);
        // Same servers within the same minute collapse into one operation (double click); a later change is a new one.
        $key = 'nameservers:' . $serviceId . ':' . substr(sha1(implode(',', $ns)), 0, 12) . ':' . intdiv(time(), 60);
        $this->store->firstOrCreate($serviceId, 'nameservers', $key, ['ns' => $ns]);
        $this->store->update($key, ['rc_order_id' => $orderId]);

        return $key;
    }

    /** Job body. Safe to run more than once for the same key. */
    public function run(string $key, int $orderId): void
    {
        $op = $this->store->find($key) ?? throw new \LogicException("Unknown operation $key");
        if (in_array($op['status'], ['submitted', 'completed', 'failed'], true)) {
            return;
        }
        $this->store->update($key, ['attempts' => $op['attempts'] + 1]);

        try {
            $res = $this->client->modifyNameservers($orderId, $op['payload']['ns']);
            $this->store->update($key, ['status' => 'submitted', 'rc_action_id' => $res->actionId]);
        } catch (RegistrarException $e) {
            if ($e->isOutcomeUnknown()) {
                // Setting nameservers is idempotent, so the retry may simply resubmit.
                $this->store->update($key, ['last_error' => $e->getMessage()]);
                throw $e;
            }
            $this->store->update($key, ['status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }
}
