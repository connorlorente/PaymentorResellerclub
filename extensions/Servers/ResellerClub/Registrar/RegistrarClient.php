<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

interface RegistrarClient
{
    /** @return array<string,string> domain => available|regthroughus|regthroughothers|unknown|premium */
    public function checkAvailability(array $labels, array $tlds): array;

    /** @param array $user name, email, company, address fields, phone. Returns the registrar customer ID. */
    public function ensureCustomer(array $user): int;

    /** @param string $type ResellerClub contact type: generic "Contact", or e.g. "UkContact" for .uk names. */
    public function createContact(array $registrant, int $customerId, string $type = 'Contact'): int;

    public function register(RegisterRequest $r): OperationResult;

    /** Transfer a domain in from another registrar. The auth (EPP) code is sent once and never stored. */
    public function transfer(RegisterRequest $r, string $authCode): OperationResult;

    public function orderIdForDomain(string $domain): ?int;

    public function orderDetails(int $orderId): OrderDetails;

    public function renew(int $orderId, int $years, int $registrarExpiryEpoch, bool $autoRenew): OperationResult;

    public function modifyNameservers(int $orderId, array $ns): OperationResult;

    public function actionStatus(int $eaqId): ActionStatus;

    /** The transfer (auth/EPP) code. Never stored: it is fetched when the owner asks and shown once. */
    public function authCode(int $orderId): string;

    /** True/false when the registrar says whether transfers out are locked, null when it does not. */
    public function transferLock(int $orderId): ?bool;

    public function setTransferLock(int $orderId, bool $locked): void;

    /** Ask the registrar to delete the registration. Only accepted inside the registry's grace period. */
    public function deleteOrder(int $orderId): OperationResult;

    /** One page (1-based) of the reseller's domain orders with their registry expiry. */
    public function searchOrders(int $page, int $perPage = 100): OrderPage;

    /** @return array<string,mixed> TLD => cost data (cached by the caller) */
    public function resellerCostPrices(): array;

    /** Cheap authenticated read; throws RegistrarException with a kind. */
    public function ping(): void;
}
