<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

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
