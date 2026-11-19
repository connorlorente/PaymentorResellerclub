<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;

/**
 * Daily expiry sync. Records the registrar expiry and reports differences; it never changes a service's status.
 */
class ExpirySync
{
    public const IN_SYNC = 'in_sync';
    public const REGISTRAR_LATER = 'registrar_later';       // informational: renewed elsewhere
    public const REGISTRAR_EARLIER = 'registrar_earlier';   // reported, no renewal operation to blame
    public const RENEWAL_FAILED = 'renewal_failed';         // a renew op claims success but the registrar disagrees

    public function __construct(
        private readonly RegistrarClient $client,
        private readonly OperationStore $store,
        private readonly int $toleranceDays = 3,
        private readonly int $perPage = 100,
    ) {}

    public static function classify(int $registrarExpiry, int $paymenterExpiry, int $toleranceDays, bool $renewalRecorded): string
    {
        $diff = $registrarExpiry - $paymenterExpiry;
        $tolerance = $toleranceDays * 86400;
        if (abs($diff) <= $tolerance) {
            return self::IN_SYNC;
        }
        if ($diff > 0) {
            return self::REGISTRAR_LATER;
        }

        return $renewalRecorded ? self::RENEWAL_FAILED : self::REGISTRAR_EARLIER;
    }

    /**
     * @param array<int,array{service_id:int, expires_at:int, renew_key:?string}> $local keyed by registrar order ID;
     *        renew_key is the newest renew operation that is submitted or completed, if any
     * @param callable(int $serviceId, int $registrarExpiry): void $saveExpiry stores rc_expiry
     * @return array<int,array{service_id:int, order_id:int, outcome:string, registrar:int, paymenter:int}> only the non-in-sync rows
     */
    public function run(array $local, callable $saveExpiry): array
    {
        $report = [];
        $seen = 0;
        $page = 1;
        do {
            $res = $this->client->searchOrders($page, $this->perPage);
            foreach ($res->orders as $order) {
                $seen++;
                if (!isset($local[$order->orderId]) || $order->expiresAt <= 0) {
                    continue;
                }
                $row = $local[$order->orderId];
                $saveExpiry($row['service_id'], $order->expiresAt);

                $outcome = self::classify($order->expiresAt, $row['expires_at'], $this->toleranceDays, $row['renew_key'] !== null);
                if ($outcome === self::RENEWAL_FAILED) {
                    $this->store->update($row['renew_key'], [
                        'status' => 'failed',
                        'last_error' => 'Registrar expiry is earlier than the renewed term; the renewal did not take effect.',
                    ]);
                }
                if ($outcome !== self::IN_SYNC) {
                    $report[] = ['service_id' => $row['service_id'], 'order_id' => $order->orderId, 'outcome' => $outcome,
                        'registrar' => $order->expiresAt, 'paymenter' => $row['expires_at']];
                }
            }
            $page++;
            // The total key is undocumented, so a full page also means "ask for the next one"; the cap stops a runaway loop.
        } while ($res->orders !== [] && ($seen < $res->total || count($res->orders) >= $this->perPage) && $page <= 1000);

        return $report;
    }
}
