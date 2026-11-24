<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class OperationResult
{
    public function __construct(
        public readonly ?int $orderId = null,
        public readonly ?int $actionId = null,
        public readonly string $status = 'submitted',
    ) {}
}
