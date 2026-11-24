<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class OrderDetails
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $expiresAt,
        public readonly array $nameservers = [],
    ) {}
}
