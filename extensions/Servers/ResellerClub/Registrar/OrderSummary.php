<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class OrderSummary
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $domain,
        public readonly int $expiresAt,
    ) {}
}
