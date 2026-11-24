<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class OrderDetails
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $expiresAt,
        public readonly array $nameservers = [],
        /** Lower-cased "currentstatus" of the order (e.g. "active", "inactive"); '' when the registrar did not say. */
        public readonly string $status = '',
        /** True when the details mention that the reseller has insufficient funds (the order is then held, not failed). */
        public readonly bool $fundsShort = false,
        /** WHOIS privacy: null = the registrar did not say. */
        public readonly ?bool $privacyAllowed = null,
        public readonly ?bool $privacyProtected = null,
    ) {
    }

    /** An order is live once the registrar says it is active, or has given it an expiry date (absent until then). */
    public function isActive(): bool
    {
        return $this->status !== '' ? $this->status === 'active' : $this->expiresAt > 0;
    }
}
