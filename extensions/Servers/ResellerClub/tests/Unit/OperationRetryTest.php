<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Core\OperationRetry;

class OperationRetryTest extends TestCase
{
    public function test_failed_register_is_reset_to_queued_and_error_cleared(): void
    {
        $store = new InMemoryOperationStore();
        $store->firstOrCreate(1, 'register', 'register:1');
        $store->update('register:1', ['status' => 'failed', 'last_error' => 'Insufficient funds', 'attempts' => 2]);

        $this->assertSame('register', (new OperationRetry($store))->reset('register:1'));
        $op = $store->find('register:1');
        $this->assertSame('queued', $op['status']);
        $this->assertNull($op['last_error']);
        $this->assertSame(2, $op['attempts']);
    }

    public function test_only_failed_register_and_renew_rows_can_be_retried(): void
    {
        $store = new InMemoryOperationStore();
        $retry = new OperationRetry($store);
        $store->firstOrCreate(1, 'renew', 'renew:1:1');
        $store->firstOrCreate(1, 'nameservers', 'ns:1');
        $store->update('ns:1', ['status' => 'failed']);

        $this->assertFalse($retry->canRetry($store->find('renew:1:1'))); // queued
        $this->assertFalse($retry->canRetry($store->find('ns:1')));      // failed but not retryable
        $this->assertFalse($retry->canRetry(null));

        $this->expectException(\LogicException::class);
        $retry->reset('ns:1');
    }
}
