<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/** Admin retry: puts a failed register or renew operation back to queued so its job can run again. */
class OperationRetry
{
    public const RETRYABLE = ['register', 'renew'];

    public function __construct(private readonly OperationStore $store) {}

    public function canRetry(?array $op): bool
    {
        return $op !== null && $op['status'] === 'failed' && in_array($op['type'], self::RETRYABLE, true);
    }

    /** @return string the operation type, so the caller can dispatch the matching job */
    public function reset(string $key): string
    {
        $op = $this->store->find($key);
        if (!$this->canRetry($op)) {
            throw new \LogicException("Operation $key cannot be retried.");
        }
        $this->store->update($key, ['status' => 'queued', 'last_error' => null]);

        return $op['type'];
    }
}
