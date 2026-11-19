<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\ActionStatus;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OperationResult;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderDetails;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderPage;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\OrderSummary;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegisterRequest;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

class FakeRegistrarClient implements RegistrarClient
{
    /** @var string[] method names of every call, in order */
    public array $calls = [];
    public int $expiry = 1_800_000_000;
    public ?RegistrarException $registerError = null;
    public ?RegistrarException $renewError = null;
    public array $existingOrders = [];
    public string $actionStatus = 'Success';

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c === $method));
    }

    /** @var array<string,string> domain => registrar status returned by checkAvailability */
    public array $availability = [];
    public bool $availabilityFails = false;

    public function checkAvailability(array $labels, array $tlds): array
    {
        $this->calls[] = __FUNCTION__;
        if ($this->availabilityFails) {
            throw new RegistrarException('down', RegistrarException::UNREACHABLE);
        }

        return $this->availability;
    }

    public function ensureCustomer(array $user): int
    {
        $this->calls[] = __FUNCTION__;

        return 11;
    }

    public function createContact(array $registrant, int $customerId): int
    {
        $this->calls[] = __FUNCTION__;

        return 22;
    }

    public function register(RegisterRequest $r): OperationResult
    {
        $this->calls[] = __FUNCTION__;
        if ($this->registerError) {
            throw $this->registerError;
        }

        return new OperationResult(555, 900);
    }

    public function orderIdForDomain(string $domain): ?int
    {
        $this->calls[] = __FUNCTION__;

        return $this->existingOrders[$domain] ?? null;
    }

    public function orderDetails(int $orderId): OrderDetails
    {
        $this->calls[] = __FUNCTION__;

        return new OrderDetails($orderId, $this->expiry);
    }

    public function renew(int $orderId, int $years, int $registrarExpiryEpoch, bool $autoRenew): OperationResult
    {
        $this->calls[] = __FUNCTION__;
        if ($this->renewError) {
            throw $this->renewError;
        }
        $this->expiry += $years * 365 * 86400;

        return new OperationResult($orderId, 901);
    }

    public function modifyNameservers(int $orderId, array $ns): OperationResult
    {
        $this->calls[] = __FUNCTION__;

        return new OperationResult($orderId, 902);
    }

    public function actionStatus(int $eaqId): ActionStatus
    {
        $this->calls[] = __FUNCTION__;

        return new ActionStatus($eaqId, $this->actionStatus);
    }

    /** @var OrderSummary[] */
    public array $orders = [];

    public function searchOrders(int $page, int $perPage = 100): OrderPage
    {
        $this->calls[] = __FUNCTION__;

        return new OrderPage(array_slice($this->orders, ($page - 1) * $perPage, $perPage), count($this->orders));
    }

    public function resellerCostPrices(): array
    {
        return [];
    }

    public function ping(): void
    {
        $this->calls[] = __FUNCTION__;
    }
}
