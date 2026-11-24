<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class OrderPage
{
    /** @param OrderSummary[] $orders */
    public function __construct(
        public readonly array $orders,
        public readonly int $total,
    ) {}
}
