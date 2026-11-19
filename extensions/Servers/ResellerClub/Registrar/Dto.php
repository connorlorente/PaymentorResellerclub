<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class RegisterRequest
{
    public function __construct(
        public readonly string $domain,
        public readonly int $years,
        public readonly array $nameservers,
        public readonly int $customerId,
        public readonly int $contactId,
        public readonly bool $privacy = false,
        public readonly bool $autoRenew = false,
        /** TLD-specific attr-name/attr-value pairs. */
        public readonly array $attributes = [],
    ) {}
}

final class OperationResult
{
    public function __construct(
        public readonly ?int $orderId = null,
        public readonly ?int $actionId = null,
        public readonly string $status = 'submitted',
    ) {}
}

final class OrderDetails
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $expiresAt,
        public readonly array $nameservers = [],
    ) {}
}

final class OrderSummary
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $domain,
        public readonly int $expiresAt,
    ) {}
}

final class OrderPage
{
    /** @param OrderSummary[] $orders */
    public function __construct(
        public readonly array $orders,
        public readonly int $total,
    ) {}
}

final class ActionStatus
{
    public function __construct(
        public readonly int $actionId,
        public readonly string $status,
        public readonly string $description = '',
    ) {}

    public function isCompleted(): bool
    {
        return in_array(strtolower($this->status), ['success', 'completed', 'complete'], true);
    }

    public function isFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'failure', 'error', 'rejected'], true);
    }
}
