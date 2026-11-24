<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

/**
 * ResellerClub legacy HTTP API client.
 *
 * The transport is injected as callable(string $method, string $url, array $params): array{status:int, body:string}
 * so tests can fake it. Endpoints marked UNCONFIRMED are not verified against official docs (see PHASE0.md).
 */
class LegacyHttpClient implements RegistrarClient
{
    public const TEST_BASE = 'https://test.httpapi.com/api/';
    public const LIVE_BASE = 'https://httpapi.com/api/'; // UNCONFIRMED live host
    public const TEST_CHECK_BASE = 'https://domaincheck.httpapi.com/api/'; // availability host

    /** @var callable */
    private $transport;

    public function __construct(
        private readonly string $resellerId,
        private readonly string $apiKey,
        private readonly bool $liveMode,
        ?callable $transport = null,
        private readonly string $liveBase = self::LIVE_BASE,
        private readonly string $checkBase = self::TEST_CHECK_BASE,
    ) {
        $this->transport = $transport ?? [$this, 'curl'];
    }

    public function ping(): void
    {
        // no-of-records must be 10 to 500 (domains/search.json article).
        $this->call('GET', 'domains/search.json', ['no-of-records' => 10, 'page-no' => 1]);
    }

    public function checkAvailability(array $labels, array $tlds): array
    {
        $json = $this->call('GET', 'domains/available.json', [
            'domain-name' => array_values($labels),
            'tlds' => array_values($tlds),
        ], $this->checkBase);

        $out = [];
        foreach ($json as $domain => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[$domain] = isset($row['costHash']) ? 'premium' : ($row['status'] ?? 'unknown');
        }

        return $out;
    }

    public function ensureCustomer(array $user): int
    {
        // A plain API error is how an unknown username answers; transport, auth and funds errors must not fall through to signup.
        try {
            $existing = $this->call('GET', 'customers/details.json', ['username' => $user['email']]);
        } catch (RegistrarException $e) {
            if ($e->kind !== RegistrarException::API) {
                throw $e;
            }
            $existing = [];
        }
        if (isset($existing['customerid'])) {
            return (int) $existing['customerid'];
        }
        $res = $this->call('POST', 'customers/signup.json', [
            'username' => $user['email'],
            'passwd' => bin2hex(random_bytes(7)), // the API wants 8 to 15 alphanumeric characters
            'name' => $user['name'],
            'company' => $user['company'] ?? 'N/A',
            'address-line-1' => $user['address'],
            'city' => $user['city'],
            'state' => $user['state'] ?? 'N/A',
            'country' => $user['country'],
            'zipcode' => $user['zip'],
            'phone-cc' => $user['phone_cc'],
            'phone' => $user['phone'],
            'lang-pref' => 'en',
        ]);

        return (int) (is_array($res) ? ($res['customerid'] ?? 0) : $res);
    }

    public function createContact(array $registrant, int $customerId, string $type = 'Contact'): int
    {
        // UNCONFIRMED: only `name` is known to be required.
        $res = $this->call('POST', 'contacts/add.json', [
            'name' => $registrant['name'],
            'company' => $registrant['company'] ?? 'N/A',
            'email' => $registrant['email'],
            'address-line-1' => $registrant['address'],
            'city' => $registrant['city'],
            'state' => $registrant['state'] ?? 'N/A',
            'country' => $registrant['country'],
            'zipcode' => $registrant['zip'],
            'phone-cc' => $registrant['phone_cc'],
            'phone' => $registrant['phone'],
            'customer-id' => $customerId,
            'type' => $type,
        ]);

        return (int) (is_array($res) ? ($res['contactid'] ?? 0) : $res);
    }

    public function register(RegisterRequest $r): OperationResult
    {
        return $this->operation($this->call('POST', 'domains/register.json', $this->orderParams($r, true)));
    }

    public function transfer(RegisterRequest $r, string $authCode): OperationResult
    {
        // domains/transfer.json: same customer/contact parameters as register; auth-code is mandatory for most TLDs.
        $params = $this->orderParams($r, false);
        if ($authCode !== '') {
            $params['auth-code'] = $authCode;
        }

        return $this->operation($this->call('POST', 'domains/transfer.json', $params));
    }

    /** Parameters shared by register and transfer. Transfers have no years (the registry adds one year). */
    private function orderParams(RegisterRequest $r, bool $withYears): array
    {
        $params = [
            'domain-name' => $r->domain,
            'years' => $r->years,
            'ns' => $r->nameservers,
            'customer-id' => $r->customerId,
            'reg-contact-id' => $r->contactId,
            'admin-contact-id' => $this->contactFor('admin', $r->domain, $r->contactId),
            'tech-contact-id' => $this->contactFor('tech', $r->domain, $r->contactId),
            'billing-contact-id' => $this->contactFor('billing', $r->domain, $r->contactId),
            'invoice-option' => 'NoInvoice',
            'auto-renew' => $r->autoRenew ? 'true' : 'false',
        ];
        if ($r->privacy) {
            $params['purchase-privacy'] = 'true'; // unsupported (and best not sent at all) on .us .uk .eu .de .ca .fr .nl .nz .ru .asia
        }
        $i = 1;
        foreach ($r->attributes as $name => $value) {
            $params["attr-name$i"] = $name;
            $params["attr-value$i"] = $value;
            $i++;
        }
        if (!$withYears) {
            unset($params['years']);
            if (!$r->nameservers) {
                unset($params['ns']); // optional on transfer: the losing registrar's nameservers are kept
            }
        }

        return $params;
    }

    /** TLDs for which the register article requires -1 instead of a contact ID, per contact role. */
    private const NO_CONTACT = [
        'admin' => ['eu', 'ru', 'uk'],
        'tech' => ['eu', 'fr', 'ru', 'uk'],
        'billing' => ['berlin', 'ca', 'eu', 'fr', 'nl', 'nz', 'ru', 'uk', 'london'],
    ];

    private function contactFor(string $role, string $domain, int $contactId): int
    {
        $tld = strtolower(substr(strrchr($domain, '.') ?: '', 1)); // co.uk -> uk

        return in_array($tld, self::NO_CONTACT[$role], true) ? -1 : $contactId;
    }

    public function orderIdForDomain(string $domain): ?int
    {
        try {
            $res = $this->call('GET', 'domains/orderid.json', ['domain-name' => $domain]);
        } catch (RegistrarException $e) {
            if ($e->kind === RegistrarException::API) {
                return null;
            }
            throw $e;
        }

        return is_numeric($res) ? (int) $res : null;
    }

    public function orderDetails(int $orderId): OrderDetails
    {
        // UNCONFIRMED: domains/details.json with options=All; field names from a third-party guide.
        $res = $this->call('GET', 'domains/details.json', ['order-id' => $orderId, 'options' => 'All']);
        $ns = [];
        foreach ($res as $k => $v) {
            if (preg_match('/^ns\d+$/', (string) $k) && $v !== '') {
                $ns[] = $v;
            }
        }

        return new OrderDetails(
            $orderId,
            (int) ($res['endtime'] ?? 0),
            $ns,
            strtolower(trim((string) ($res['currentstatus'] ?? ''))),
            self::mentions($res, 'insufficient funds'),
            self::boolOrNull($res['privacyprotectedallowed'] ?? null),
            self::boolOrNull($res['privacyprotected'] ?? $res['isprivacyprotected'] ?? null),
        );
    }

    private static function boolOrNull(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v) && in_array(strtolower($v), ['true', 'false'], true)) {
            return strtolower($v) === 'true';
        }

        return null;
    }

    /** True when any text anywhere in the response contains $needle. The funds reason has no documented field. */
    private static function mentions(mixed $data, string $needle): bool
    {
        if (is_array($data)) {
            foreach ($data as $v) {
                if (self::mentions($v, $needle)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($data) && stripos($data, $needle) !== false;
    }

    public function setPrivacy(int $orderId, bool $protect): void
    {
        // UNCONFIRMED: domains/modify-privacy-protection.json. Only for domains that already have privacy purchased.
        $this->call('POST', 'domains/modify-privacy-protection.json', [
            'order-id' => $orderId,
            'protect-privacy' => $protect ? 'true' : 'false',
            'reason' => 'Changed by the customer in the client area',
        ]);
    }

    public function renew(int $orderId, int $years, int $registrarExpiryEpoch, bool $autoRenew): OperationResult
    {
        return $this->operation($this->call('POST', 'domains/renew.json', [
            'order-id' => $orderId,
            'years' => $years,
            'exp-date' => $registrarExpiryEpoch,
            'auto-renew' => $autoRenew ? 'true' : 'false',
            'invoice-option' => 'NoInvoice', // confirm no customer invoice is raised
        ]));
    }

    public function modifyNameservers(int $orderId, array $ns): OperationResult
    {
        return $this->operation($this->call('POST', 'domains/modify-ns.json', [
            'order-id' => $orderId,
            'ns' => array_values($ns),
        ]), $orderId);
    }

    public function actionStatus(int $eaqId): ActionStatus
    {
        try {
            $res = $this->call('GET', 'actions/search-current.json', [
                'no-of-records' => 10,
                'page-no' => 1,
                'eaq-id' => [$eaqId],
            ]);
        } catch (RegistrarException $e) {
            // An id with no current action answers HTTP 500 {"status":"ERROR","message":"No record found"}.
            if ($e->kind === RegistrarException::API && stripos($e->getMessage(), 'no record') !== false) {
                return new ActionStatus($eaqId, 'unknown');
            }
            throw $e;
        }
        // The article does not show the row keys, so find the row by its eaqid rather than by position.
        $row = null;
        foreach ((array) $res as $candidate) {
            if (is_array($candidate) && (int) ($candidate['eaqid'] ?? 0) === $eaqId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return new ActionStatus($eaqId, 'unknown');
        }

        return new ActionStatus($eaqId, (string) ($row['actionstatus'] ?? 'unknown'), (string) ($row['actionstatusdesc'] ?? ''));
    }

    public function authCode(int $orderId): string
    {
        // UNCONFIRMED against a live order: the code is the "domsecret" field of domains/details.json (as WHMCS's module reads it).
        $res = $this->call('GET', 'domains/details.json', ['order-id' => $orderId, 'options' => 'All']);
        $code = is_array($res) ? trim((string) ($res['domsecret'] ?? '')) : '';
        if ($code === '') {
            throw new RegistrarException('The registrar did not return a transfer code for this domain.', RegistrarException::API);
        }

        return $code;
    }

    public function transferLock(int $orderId): ?bool
    {
        // UNCONFIRMED: the lock shows up as "transferlock" in the order status list of domains/details.json.
        $res = $this->call('GET', 'domains/details.json', ['order-id' => $orderId, 'options' => 'All']);
        foreach (['orderstatus', 'domainstatus'] as $key) {
            if (is_array($res) && isset($res[$key]) && is_array($res[$key])) {
                return in_array('transferlock', array_map(fn ($v) => strtolower((string) $v), $res[$key]), true);
            }
        }

        return null;
    }

    public function setTransferLock(int $orderId, bool $locked): void
    {
        $this->call('POST', $locked ? 'domains/enable-theft-protection.json' : 'domains/disable-theft-protection.json', ['order-id' => $orderId]);
    }

    public function deleteOrder(int $orderId): OperationResult
    {
        return $this->operation($this->call('POST', 'domains/delete.json', ['order-id' => $orderId]), $orderId);
    }

    public function searchOrders(int $page, int $perPage = 100): OrderPage
    {
        // UNCONFIRMED: response keys ("orders.orderid", "entity.description", "orders.endtime") are from the API article, not a recorded response.
        $res = $this->call('GET', 'domains/search.json', ['no-of-records' => min(500, max(10, $perPage)), 'page-no' => $page, 'order-by' => 'orderid']);
        $orders = [];
        foreach ($res as $k => $row) {
            if (!is_numeric($k) || !is_array($row)) {
                continue; // skip recsonpage / recsindb
            }
            $orders[] = new OrderSummary(
                (int) ($row['orders.orderid'] ?? 0),
                strtolower((string) ($row['entity.description'] ?? '')),
                (int) ($row['orders.endtime'] ?? 0),
            );
        }

        return new OrderPage($orders, (int) ($res['recsindb'] ?? count($orders)));
    }

    public function resellerCostPrices(): array
    {
        return $this->call('GET', 'products/reseller-cost-price.json', []);
    }

    private function operation(array $res, ?int $orderId = null): OperationResult
    {
        return new OperationResult(
            isset($res['entityid']) ? (int) $res['entityid'] : $orderId,
            isset($res['eaqid']) ? (int) $res['eaqid'] : null,
            (string) ($res['actionstatus'] ?? $res['status'] ?? 'submitted'),
        );
    }

    /** @return mixed decoded JSON */
    private function call(string $method, string $path, array $params, ?string $base = null): mixed
    {
        $base ??= $this->liveMode ? $this->liveBase : self::TEST_BASE;
        $params = ['auth-userid' => $this->resellerId, 'api-key' => $this->apiKey] + $params;

        try {
            $resp = ($this->transport)($method, rtrim($base, '/') . '/' . $path, $params);
        } catch (RegistrarException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RegistrarException('Could not reach ResellerClub: ' . $this->redact($e->getMessage()), RegistrarException::UNREACHABLE);
        }

        $body = trim($resp['body']);
        $json = json_decode($body, true);

        // Cloudflare in front of httpapi.com can refuse the request before ResellerClub sees it (HTML block page).
        // That is not the IP whitelist, so say so: the fix is different (contact ResellerClub with the Ray ID).
        if ($json === null && ($resp['status'] === 403 || $resp['status'] === 503) && (stripos($body, 'cloudflare') !== false || stripos($body, '<html') !== false)) {
            $ray = preg_match('/Ray ID:?\s*(?:<[^>]+>\s*)*([0-9a-f]{16})/i', $body, $m) ? $m[1] : null;
            $ip = preg_match('/id="cf-footer-ip">([^<]+)</i', $body, $m2) ? trim($m2[1]) : null;
            throw new RegistrarException(
                'Blocked by ResellerClub\'s Cloudflare firewall (HTTP ' . $resp['status'] . ') before the API was reached'
                . ($ip ? "; this server's outgoing IP is $ip" : '') . ($ray ? "; Cloudflare Ray ID $ray" : '')
                . '. Ask ResellerClub support to allow this IP; the API whitelist alone does not help.',
                RegistrarException::UNREACHABLE,
            );
        }

        // A non-whitelisted IP is documented as: "Access Denied. You are not allowed to perform this action."
        if ($resp['status'] === 403 || stripos($body, 'not allowed to perform') !== false || stripos($body, 'access denied') !== false) {
            throw new RegistrarException('Request rejected: the server IP is probably not whitelisted at Settings > API in ResellerClub.', RegistrarException::IP_NOT_WHITELISTED);
        }
        if ($json === null && !is_numeric($body)) {
            throw new RegistrarException('Unexpected ResellerClub response (HTTP ' . $resp['status'] . ').', RegistrarException::API);
        }
        $json ??= (int) $body;

        // Both {"status":"ERROR","message":..} and {"status":"error","error":..} occur (the latter on register.json).
        if (is_array($json) && is_string($json['status'] ?? null) && strcasecmp($json['status'], 'error') === 0) {
            $msg = (string) ($json['message'] ?? $json['error'] ?? 'Unknown error');
            // Name the call that failed (and the non-secret ids it was sent): "Invalid domainName For Given ContactType"
            // alone cannot say whether the contact creation or the registration produced it.
            throw new RegistrarException('[' . $path . $this->describe($params) . '] ' . $this->redact($msg), $this->classify($msg));
        }

        return $json;
    }

    private function classify(string $msg): string
    {
        $m = strtolower($msg);

        return match (true) {
            str_contains($m, 'invalid credentials'), str_contains($m, 'authentication'), str_contains($m, 'api key'), str_contains($m, 'invalid api') => RegistrarException::AUTH,
            str_contains($m, 'whitelist'), str_contains($m, 'ip address'), str_contains($m, 'not allowed to perform'), str_contains($m, 'access denied') => RegistrarException::IP_NOT_WHITELISTED,
            str_contains($m, 'insufficient'), str_contains($m, 'funds'), str_contains($m, 'balance') => RegistrarException::FUNDS,
            str_contains($m, 'not available'), str_contains($m, 'already registered'), str_contains($m, 'already exists') => RegistrarException::TAKEN,
            default => RegistrarException::API,
        };
    }

    /** Only identifiers and settings that help to understand a failed call; never credentials, names, addresses or codes. */
    private function describe(array $params): string
    {
        $safe = ['domain-name', 'years', 'customer-id', 'reg-contact-id', 'admin-contact-id', 'tech-contact-id', 'billing-contact-id', 'type', 'order-id', 'invoice-option'];
        $parts = [];
        foreach ($safe as $k) {
            if (array_key_exists($k, $params)) {
                $parts[] = $k . '=' . (is_array($params[$k]) ? implode(',', $params[$k]) : $params[$k]);
            }
        }

        return $parts ? ' ' . implode(' ', $parts) : '';
    }

    private function redact(string $s): string
    {
        return $this->apiKey === '' ? $s : str_replace($this->apiKey, '[redacted]', $s);
    }

    private function curl(string $method, string $url, array $params): array
    {
        // ResellerClub expects repeated keys (ns=a&ns=b), not ns[0]=a.
        $parts = [];
        foreach ($params as $k => $v) {
            foreach ((array) $v as $item) {
                $parts[] = rawurlencode($k) . '=' . rawurlencode((string) $item);
            }
        }
        $query = implode('&', $parts);

        $ch = curl_init($method === 'GET' ? $url . '?' . $query : $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Availability lookups run while a customer waits at checkout: give up fast and let the page say "try again".
            CURLOPT_TIMEOUT => str_contains($url, '//domaincheck.') ? 10 : 30,
            CURLOPT_CONNECTTIMEOUT => str_contains($url, '//domaincheck.') ? 5 : 10,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new RegistrarException($err, $errno === CURLE_OPERATION_TIMEDOUT ? RegistrarException::TIMEOUT : RegistrarException::UNREACHABLE);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
