<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

interface RegistrarClient
{
    /** @return array<string,string> domain => available|regthroughus|regthroughothers|unknown|premium */
    public function checkAvailability(array $labels, array $tlds): array;

    /** @param array $user name, email, company, address fields, phone. Returns the registrar customer ID. */
    public function ensureCustomer(array $user): int;

    public function createContact(array $registrant, int $customerId): int;

    public function register(RegisterRequest $r): OperationResult;

    public function orderIdForDomain(string $domain): ?int;

    public function orderDetails(int $orderId): OrderDetails;

    public function renew(int $orderId, int $years, int $registrarExpiryEpoch, bool $autoRenew): OperationResult;

    public function modifyNameservers(int $orderId, array $ns): OperationResult;

    public function actionStatus(int $eaqId): ActionStatus;

    /** One page (1-based) of the reseller's domain orders with their registry expiry. */
    public function searchOrders(int $page, int $perPage = 100): OrderPage;

    /** @return array<string,mixed> TLD => cost data (cached by the caller) */
    public function resellerCostPrices(): array;

    /** Cheap authenticated read; throws RegistrarException with a kind. */
    public function ping(): void;
}
