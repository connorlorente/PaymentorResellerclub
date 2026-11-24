<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

/**
 * Follows a submitted registration or transfer until the registrar order is really live.
 *
 * The registrar accepts an order even when the reseller balance cannot pay for it: the order is created but stays
 * inactive, "locked in processing", and the reason is only visible in the order details. That is deliberately how this
 * site is run (the account is kept unfunded so a mistyped or spam order never costs money), so it is a normal waiting
 * state, not a failure:
 *   submitted -> completed        the order is active (an admin funded the account, or it was funded all along)
 *   submitted -> awaiting_funds   the details say the reseller has insufficient funds; the admin is told once
 *   awaiting_funds -> completed   funds arrived and the registrar activated the order
 */
class RegistrationWatcher
{
    public const WATCHED_TYPES = ['register', 'transfer'];
    public const WATCHED_STATUSES = ['submitted', 'awaiting_funds'];

    /**
     * @param callable(array):void $onFunds  called once, when an order first turns out to be waiting for funds
     * @param callable(array,int):void $onActive called once, when the order becomes active (op row, registrar expiry)
     */
    public function __construct(
        private readonly RegistrarClient $client,
        private readonly OperationStore $store,
        private $onFunds,
        private $onActive,
    ) {
    }

    /** @return string the operation's status afterwards; unchanged when the registrar could not be asked or has nothing new. */
    public function check(string $key): string
    {
        $op = $this->store->find($key);
        if (!$op || !in_array($op['type'], self::WATCHED_TYPES, true) || !in_array($op['status'], self::WATCHED_STATUSES, true) || empty($op['rc_order_id'])) {
            return $op['status'] ?? 'missing';
        }

        try {
            $details = $this->client->orderDetails((int) $op['rc_order_id']);
        } catch (RegistrarException) {
            return $op['status']; // an outage must not turn a waiting order into a failed one
        }

        if ($details->isActive()) {
            $this->store->update($key, ['status' => 'completed', 'last_error' => null]);
            ($this->onActive)($this->store->find($key), $details->expiresAt);

            return 'completed';
        }

        if ($details->fundsShort) {
            if ($op['status'] !== 'awaiting_funds') {
                $this->store->update($key, [
                    'status' => 'awaiting_funds',
                    'last_error' => 'Waiting for ResellerClub funds: the order is held at the registrar and completes by itself once the reseller balance covers it. Cancel the service to drop the order instead.',
                ]);
                ($this->onFunds)($this->store->find($key));
            }

            return 'awaiting_funds';
        }

        return $op['status'];
    }
}
