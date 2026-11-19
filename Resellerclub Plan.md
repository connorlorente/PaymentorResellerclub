# Paymenter + ResellerClub domains extension: source findings

Oct 27, 2026 · @Connor Lorente

## Overview

This spec defines a Paymenter Server extension that sells and manages ResellerClub domains: one Paymenter product per TLD, registration on first payment, a registrar renewal on each paid renewal, and customer self-service for nameservers, theft protection, privacy and the transfer code.

### Goals

- Register a domain at ResellerClub when the first invoice is paid.
- Renew at ResellerClub exactly once per paid renewal invoice.
- Let customers manage nameservers, theft protection, privacy and the transfer (EPP) code from the service page.
- Sync registrar expiry and TLD cost into Paymenter on a schedule.
- Give admins a connection test, an operations log and a retry button.

### Non-goals for v1

- ResellerClub hosting, email, SSL and other non-domain products. The [developer portal](https://www.resellerclub.com/developer-portal) says these stay on the legacy API for now.
- Premium and early-access domains. Search shows them as unavailable.
- A registrar-neutral abstraction beyond one client interface.

### Assumptions

- The legacy HTTP API is the base. It covers the full domain lifecycle with official articles, while the portal presents the v2 REST API as newly rebuilt. The client sits behind an interface so v2 can replace it later.
- Paymenter runs on PHP 8.3 or higher, as the [repo README](https://github.com/Paymenter/Paymenter) states.
- Transfers in and DNS management are phase 4, not MVP. This is unconfirmed; see open questions.
- The target Paymenter version is the one you run. Marketplace listings show extensions supporting 1.4 and 1.5.

### Acceptance criteria

1. Paying a first invoice for a TLD product registers the domain on the ResellerClub demo account and stores its order ID on the service.
2. Paying a renewal invoice extends the registrar expiry by the billed term, once. A second save of the service, a repeated event, or refunding a cancelled invoice (Paymenter issue [#1416](https://github.com/Paymenter/Paymenter/issues/1416)) causes no second renewal call.
3. A service reaching suspension or termination in the cron job never deletes, releases or transfers the domain.
4. A customer can change nameservers and sees the result once the registrar action completes.
5. A domain taken between checkout and payment ends in an admin-visible failed operation, not a silent pending service.
6. The connection test reports credential failures and IP-whitelist failures as different messages.

7) With **Allow new registrations** switched off, search and checkout offer no new domains, a paid first invoice does not register, and renewals and management still work.

## Architecture

The extension is one Paymenter Server extension. Paymenter calls its hooks; the hooks and the renewal listener only record an operation and queue a job; the queue job is the only code that calls ResellerClub, through the `RegistrarClient` interface from the API section.

&#91;embedded content: architecture · extension, queue, ResellerClub\]

Routes, the domain search page and the scheduled commands are registered in `boot()`. Three consequences follow:

- Retries, throttling and idempotency live in the job layer, so hooks stay thin.
- Tests replace `RegistrarClient` with a fake, and a move to the v2 REST API is a new client class, not a rewrite.
- Customer and admin pages read the operations table, so a registrar outage does not blank them.

## Data model

Registrar identifiers live in Paymenter's existing property tables. Operation history lives in one extension table so renewals can be made idempotent.

### Properties

Built-in servers store IDs with `$service->properties()->updateOrCreate(['key'=>…], ['name'=>…, 'value'=>…])`, and the Enhance extension stores per-user IDs on `$user->properties()`. The cart also copies checkout values into service properties. This extension follows the same pattern, with an `rc_` prefix.

| Key | Scope | Written by | Purpose |
| --- | --- | --- | --- |
| `domain_name` | service | checkout config | Full domain: label plus TLD. |
| `rc_order_id` | service | `createServer` | ResellerClub order ID. Absent until registration succeeds. |
| `rc_customer_id` | user | `ensureCustomer` | ResellerClub customer for this Paymenter user. |
| `rc_contact_id` | service | `createServer` | Registrant contact. One contact per domain mirrors the [WHMCS module](https://whmcsdocs.devops.cpanel.net/8-13/domains/domain-registrar-modules/resellerclub), which creates a unique contact for each domain. |
| `rc_expiry` | service | sync job, renewal | Registrar expiry last seen (epoch). Never used as the renewal date; the live value is fetched first. |
| registrant fields | service | checkout config | Name, email, address, phone. Exact keys are defined with the checkout form. |

### Operations table

A single table, `ext_resellerclub_operations`, records every registrar write. Its unique `idempotency_key` is what stops a repeated event from renewing twice.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | Primary key. |
| `service_id` | bigint | Foreign key to services. |
| `type` | string | register, renew, nameservers, and later transfer, privacy. |
| `idempotency_key` | string, unique | For example `renew:{service_id}:{new_expires_at}`. |
| `rc_order_id` | bigint, nullable | Filled once known. |
| `rc_action_id` | bigint, nullable | The `eaqid` returned by the call; polled with the actions search. |
| `status` | string | queued, submitted, completed, failed. |
| `attempts` | int | For backoff. |
| `last_error` | text, nullable | Redacted: never the API key or an auth code. |
| `payload` | json | Request summary without secrets. |
| timestamps |  | `created_at`, `updated_at`. |

### Migrations

Run the migration from `installed()` and `upgraded()` with `ExtensionHelper::runMigrations(...)`, and roll back in `uninstalled()`. The [extensions docs](https://paymenter.org/development/extensions/) show the path as `extension/Others/Example/database/migrations`, but the repo folder is `extensions/`. Confirm the correct path with a throwaway extension before relying on it.

## ResellerClub API client

The client is a plain PHP class behind a `RegistrarClient` interface with no Paymenter dependencies. Unit tests fake its HTTP layer, and a v2 implementation can replace it later.

### Environments and authentication

- **Test base:** `https://test.httpapi.com/api/`. Every official article shows its example against this host, with `auth-userid` and `api-key` as request parameters.
- **Live base:** the articles show only the test host. A [third-party integration guide](https://domaindetails.com/kb/technical-guides/domain-api-integration-automation) uses `https://httpapi.com/api`. Confirm the live host before go-live.
- **Availability host:** availability uses `domaincheck.httpapi.com/api/domains/available.json`, a separate host from the rest. ([article](https://www.resellerclub.com/help/article/Check-Domain-Availability-via-API))
- **Credentials:** the reseller ID is `auth-userid`, and the API key comes from Settings > API. The server's outbound IP must be whitelisted on the same page or calls fail with a connection error. ([WHMCS docs](https://whmcsdocs.devops.cpanel.net/8-13/domains/domain-registrar-modules/resellerclub))
- **Test mode** needs a separate demo account. WISECP warns that ResellerClub can perform real operations with live credentials even at its test address. ([WISECP](https://docs.wisecp.com/de/resellerclub-module))
- **Errors:** failed calls return `status: ERROR` with a message, as the modify-ns, transfer and actions articles document. The client throws a typed exception on that shape.
- **Rate limits** are not documented in the articles read. The client throttles requests and retries only idempotent reads.

### Verified endpoints

Each row comes from an official ResellerClub article read for this spec.

| Capability | Endpoint | Key parameters | Returns and notes |
| --- | --- | --- | --- |
| Availability | GET `domains/available.json` ([doc](https://www.resellerclub.com/help/article/Check-Domain-Availability-via-API)) | `domain-name[]`, `tlds[]` | `status`: available, regthroughus, regthroughothers, unknown. Premium names return a `costHash`. Limits: 5 names per Donuts TLD group and 15 strings for .CA per call, else `unknown`. |
| Register | `domains/register.json` ([doc](https://www.resellerclub.com/help/article/Register-Domain-Names-Using-the-API)) | `domain-name`, `years`, `ns[]`, `customer-id`, `reg-`, `admin-`, `tech-`, `billing-contact-id`; optional privacy, auto-renew, `attr-name`/`attr-value` | Order and action IDs. Extra attributes carry TLD-specific details. |
| Renew | `domains/renew.json` ([doc](https://www.resellerclub.com/help/article/How-to-Renew-a-Domain-Name-Using-the-API)) | `order-id`, `years`, `exp-date` (current expiry, epoch), `auto-renew`; optional `purchase-privacy` | The examples pass `invoice-option=NoInvoice`. |
| Nameservers | POST `domains/modify-ns.json` ([doc](https://cp.resellerclub.com/kb/node/776)) | `order-id`, `ns[]`; optional `otp`, `2fa-type` | Action fields: `eaqid`, `actionstatus`, `actionstatusdesc`. |
| Order ID by domain | GET `domains/orderid.json` ([doc](https://www.resellerclub.com/help/article/Get-the-Order-ID-of-a-Domain-Registration-Order-Using-the-API)) | `domain-name` | The order ID. |
| Search orders | `domains/search.json` ([doc](https://cnlogin.resellerclub.com/kb/node/771)) | `no-of-records`, `page-no`; optional `customer-id[]`, `order-id[]`, `expiry-date-start`, `expiry-date-end` (UNIX), `order-by` | `orders.orderid`, `entity.customerid`, `orders.endtime` (registry expiry) and more. |
| Action status | GET `actions/search-current.json` ([doc](https://www.resellerclub.com/help/article/Search-Current-Actions-Using-the-API)) | `no-of-records`, `page-no`; optional `eaq-id[]`, `order-id[]`, `action-status[]` | `actionstatus`, `actionstatusdesc`, timestamps. The article does not list the status values. An [archived search](https://www.resellerclub.com/help/article/Search-Archived-Actions-Using-the-API) also exists. |
| Transfer in | POST `domains/transfer.json` ([doc](https://www.resellerclub.com/help/article/Transfer-a-Domain-Name-Using-the-API)) | `domain-name`, `auth-code`, customer and contact IDs, `invoice-option` | The domain must be unlocked. Auth code is mandatory at order time for .AU, .CA, .DE, .EU and .NZ. |
| Submit auth code | POST `domains/transfer/submit-auth-code` ([doc](https://manage.resellerclub.com/kb/node/2446)) | `order-id`, `auth-code` | Continues a pending transfer. |
| Cost pricing | `products/reseller-cost-price.json` ([doc](https://www.resellerclub.com/help/article/Get-Reseller-Cost-Pricing-Details-Using-the-API)) | none required | ResellerClub recommends caching it because the response is large. Customer prices: [customer-price.json](https://www.resellerclub.com/help/article/How-to-Fetch-Customer-Pricing-Using-the-Products-Pricing-API). |

### To confirm before coding

These exist in the ResellerClub knowledge base, but their article bodies were not readable here, or only a third-party library documents the call. Confirm each against the demo account in phase 0.

| Capability | What is known | Source |
| --- | --- | --- |
| Create customer | A third-party library calls POST `/customers/signup.json` with `username` (email), `passwd`, `name`, `company`, address fields, `phone-cc`, `phone`, `lang-pref`. | [orderbox.php](https://github.com/notmaintained/orderbox.php) |
| Add contact | The official article exists. It lists `name` (max 255 characters) as required; the rest was not readable. | [Add Contact](https://cp.nic.gi/kb/answer/790) |
| Order details | Official articles for details by order ID and by domain name exist. A third-party guide uses `domains/details.json`. | [API index](https://www.resellerclub.com/help/topic/api_domains), [guide](https://domaindetails.com/kb/technical-guides/domain-api-integration-automation) |
| Theft protection on/off | Official articles for enabling and disabling exist. A Laravel package wraps `enableTheftProtection`. | [disable](https://cp.resellerclub.com/kb/node/903), [package](https://github.com/netinternet/logicboxes-api) |
| Lock list | "Get the List of Locks Applied on a Domain Name" exists. | [article](https://cp.resellerclub.com/kb/node/897) |
| Privacy on/off | "Modifying the Privacy Protection Status" exists. | [article](https://cp.cn.resellerclub.com/kb/node/778) |
| Auth (EPP) code | An article on locating and modifying the domain secret exists. Confirm whether the API returns it. | [article](https://manage.resellerclub.com/kb/node/50) |
| DNS records | Per-record-type endpoints, for example add A record. | [SDK listing](https://awesome.ecosyste.ms/projects/github.com%2Fpitchero%2Fresellerclub) |
| Reseller balance | A third-party library uses `/billing/reseller-balance.json`. | [orderbox.php](https://github.com/notmaintained/orderbox.php) |

### Client interface

```php
interface RegistrarClient
{
    public function checkAvailability(array $labels, array $tlds): array;
    public function ensureCustomer(User $user): int;            // cached as user property rc_customer_id
    public function createContact(array $registrant, int $customerId): int;
    public function register(RegisterRequest $r): OperationResult;   // order id + eaqid
    public function orderIdForDomain(string $domain): ?int;
    public function orderDetails(int $orderId): OrderDetails;        // includes registrar expiry
    public function renew(int $orderId, int $years, int $registrarExpiryEpoch, bool $autoRenew): OperationResult;
    public function modifyNameservers(int $orderId, array $ns): OperationResult;
    public function actionStatus(int $eaqId): ActionStatus;
    public function searchOrders(OrderSearch $q): OrderPage;
    public function resellerCostPrices(): array;
}
```

Phase 4 adds transfer, privacy, theft protection, auth code and DNS methods. Renewals use `invoice-option=NoInvoice` on the assumption that Paymenter does the customer billing; confirm that ResellerClub then debits the reseller balance without raising a customer invoice.

## Paymenter integration

The extension is a Server extension, so the product's server setting routes lifecycle calls to it. Paymenter has no renewal or domain-search hook, so those two are built from `boot()` listeners and routes. Source references are at commit `ef469ff` (see the findings table below).

| Paymenter hook | What this extension does |
| --- | --- |
| `getConfig` | Reseller ID, API key, live or test mode, outbound IP note, default registrant fallback. Stored as server settings and read with `$this->config('key')`. |
| `testConfig` | Calls a cheap authenticated read. Returns `true` or an error string that separates a bad key from a non-whitelisted IP. |
| `getProductConfig` | Per product: TLD, registration years, privacy default, markup rule. One product per TLD. |
| `getCheckoutConfig` | Domain label, registrant fields, nameserver choice. Values are copied into service properties by the cart. Validation accepts an array, merged into the rules, so a custom availability rule can be passed as an object. |
| `createServer` | Queued by Paymenter after the first payment. Ensures the customer, creates the contact, calls register, stores `rc_order_id`, writes an operations row. |
| `suspendServer` | Does not touch the registrar. Optionally records a note; never lock-only by default. |
| `unsuspendServer` | No-op that returns true. |
| `terminateServer` | No-op that returns true. The cron job terminates after 14 days overdue by default, and a domain must never be deleted by that. |
| `upgradeServer` | Not supported in v1. |
| `getActions` | Customer actions on the service page: nameservers (view), lock, privacy, auth code (phase 4). |
| `boot` | Registers routes, the search Livewire component, the `Service\Updated` listener for renewals, and the scheduled commands. |
| `installed` / `upgraded` / `uninstalled` | Run or roll back the operations migration. |

### Renewal listener

`RenewServiceService::handle` only extends `expires_at` and sets the status to active, so renewal at the registrar has to be derived from a change in `expires_at`. The listener on `App\Events\Service\Updated` must:

1. Ignore services whose product server is not this extension.
2. Ignore saves where `expires_at` did not change forward.
3. Dispatch a queued renewal job keyed by `renew:{service_id}:{new_expires_at}`, so duplicate events collapse into one operations row.

The job then applies the idempotency guard described in Core flows.

### Constraints from the source

- Pending services call `createServer` through `RenewServiceService`, so the first payment and the registration share one code path.
- The cron job can mark a service paid at zero price and call the same renewal path, so zero-price renewals still renew at the registrar.
- The base `Server` class is empty, so every hook above is a convention, not an inherited contract. A missing method makes the helper throw.

## Core flows

Two flows carry the extension: registration on the first paid invoice, and renewal on each later one. The renewal flow has the one guard that matters, because Paymenter can run its renewal path more than once for the same payment.

&#91;embedded content: registration and renewal · 2 flows, 1 guard\]

### Registration

1. Paymenter marks the first invoice paid and runs `createServer`, which records a `register` operation.
2. The job reuses the user's `rc_customer_id` or creates the customer, then creates the registrant contact. The customer and contact endpoints are in the "to confirm" table.
3. It calls `register.json` with the years, nameservers and contact IDs. Failures such as a taken name or short funds mark the operation failed and alert the admin, and the service stays pending.
4. On success it stores `rc_order_id`, then polls the returned action ID until the registrar reports completion.

### Renewal

1. `RenewServiceService` moves `expires_at` forward. The `Service\Updated` listener sees the change and queues one job under the key `renew:{service_id}:{new_expires_at}`; a duplicate key is dropped.
2. The job reads the live order details from the registrar. It never trusts the stored `rc_expiry`.
3. **Guard:** if the registrar expiry already reaches the target, the job records completed and makes no call. The target is the new `expires_at`, compared with a tolerance of a few days because the two systems count terms differently. The tolerance is a design choice to settle in phase 0.
4. Otherwise it calls `renew.json` with the registrar's own expiry as `exp-date`, then polls the action and updates `rc_expiry`.

The guard is what makes the issue #1416 case safe: refunding a cancelled paid invoice re-renews the Paymenter service, but the registrar expiry is already at the target, so no second call or charge is made.

## Checkout and domain search

Paymenter has no domain concept, so search is a custom page that feeds the chosen name into the normal product checkout.

### Search page

- A route and Livewire component registered in `boot()`, at a path such as `/domains`.
- Input: a label (and optionally a TLD). The component calls availability for the TLDs that have an enabled Paymenter product, and shows only those.
- Each result row links to that TLD's product checkout with the full domain prefilled in the `config` query value, which Paymenter's checkout already reads from the URL.
- Availability uses the separate `domaincheck.httpapi.com` host. Responses are cached for about a minute to cut calls.

### Result handling

| Registrar status | Shown as |
| --- | --- |
| `available` | Buy button with the product price. |
| `regthroughus` | Taken. |
| `regthroughothers` | Taken (registered elsewhere). |
| `unknown` | "Could not check"; retry. Also what comes back when the per-call name limits are exceeded (5 names per Donuts TLD group, 15 for .CA). |
| premium (a `costHash` is returned) | Hidden or shown as unavailable in v1. |

### Checkout fields

Returned by `getCheckoutConfig`:

- `domain_name` (required, locked to the search result, revalidated server side).
- Registrant: name, email, phone, address fields, company optional.
- Nameservers: default (admin setting) or custom.

The validation array includes a rule that re-checks availability at submit. It protects against a name taken since the search, but not against a name taken between checkout and payment; that case is covered by the failed-operation path.

### Pricing

One plan per registration term on each TLD product, priced from the cost sync plus markup. Whether a plan can use a yearly billing unit is unconfirmed; if not, 12 monthly units is the fallback. Renewal price follows the plan, so premium TLD renewals above registration price need their own plan.

### Cart behavior

Each domain is its own product line. Quantity is disabled for these products so a customer cannot buy one name twice, which Paymenter's checkout already enforces when `allow_quantity` is disabled.

## Customer domain management

Customers manage a domain from its service page through `getActions`. Each action calls the client, writes an operations row, and shows the registrar's action status.

| Action | Phase | Endpoint status | Notes |
| --- | --- | --- | --- |
| View nameservers and expiry | MVP | Order details to confirm | Reads from the registrar, falling back to cached `rc_expiry`. |
| Change nameservers | MVP | Verified (`modify-ns.json`) | Validates 2 to 13 hostnames in the form, queues the call, polls the action ID. Some TLDs may need an OTP; the article lists optional `otp` and `2fa-type`. |
| Theft protection on/off | 2 | To confirm | Required before a transfer-out. |
| Privacy on/off | 2 | To confirm | Not available on every TLD; hide the control where the registrar refuses. |
| Auth (EPP) code | 2 | To confirm | Shown once, never logged or stored in properties. |
| Transfer in | 4 | Verified (`transfer.json`, submit-auth-code) | Separate product and flow. |
| DNS records | 4 | To confirm (third-party SDK only) | Only when the domain uses ResellerClub DNS. |

### Behavior rules

- Every write is asynchronous at the registrar. The UI shows "pending" until the action search reports completion, then updates.
- Actions are rate limited per service in Paymenter, not just at the client.
- Actions are hidden when the service is suspended or has no `rc_order_id`.
- Customers can only act on their own services; Paymenter's service ownership checks apply, and the extension never trusts an order ID from the request.

## Admin configuration, TLD products and pricing sync

### Server settings

| Setting | Notes |
| --- | --- |
| Reseller ID | Sent as `auth-userid`. |
| API key | Encrypted setting; never rendered back to the form. |
| Mode | Live or test. Test uses the demo account and the test host. |
| Default nameservers | Applied when the customer does not choose. |
| Default markup | Percentage or fixed, overridable per product. |
| Low-balance alert | Threshold for the reseller funds balance, if the balance endpoint is confirmed. |

`testConfig` returns a specific message for bad credentials, a non-whitelisted IP, and unreachable host.

### Registration switch (renewals and management only)

An admin setting, **Allow new registrations** (on by default), lets the reseller stop selling new domains while existing ones keep renewing and being managed. For example, when winding down, or when the ResellerClub balance is being held back.

With it off:

- **Search and checkout:** the search page shows a notice instead of results, and makes no availability calls. TLD products stop being purchasable, and `getCheckoutConfig` refuses new domain names. Cart items already added are rejected at checkout with a clear message.
- **Registration path:** `createServer` fails the operation with a "registrations disabled" reason, so a first invoice paid after the switch was flipped never registers silently. The service stays pending and the admin sees it in the operations list, to refund or retry once the switch is back on.
- **Unaffected:** renewals, the renewal listener, nameservers, theft protection, privacy, auth code, the expiry, pricing and action-polling jobs, and the admin pages.
- **Transfers in** (phase 4) count as new domains and follow the switch.

The setting is read from the server settings through `$this->config('allow_registrations')` on each request, so changing it takes effect immediately with no cache to clear. Hiding the TLD products from the storefront is a separate, manual step in Paymenter; the switch alone does not delete or unpublish them. Domains already in the cart or in unpaid first invoices at the moment of the switch are the one case to decide: this spec fails them at payment time, as above, rather than silently cancelling them.

### TLD products

An artisan command and an admin action create one Paymenter product per TLD, with plans for 1 to 10 years where the registry allows it. The product holds the TLD in its product config, so pricing and checkout read it from there.

### Pricing sync

- Source: `products/reseller-cost-price.json`, which ResellerClub advises caching because the response is large. The job fetches it nightly and stores the per-TLD figures in the extension's cache.
- Plan price = cost x (1 + markup), converted to the Paymenter currency if it differs. The job proposes changes and applies them only when the admin enables auto-apply.
- Customer-price endpoints exist, but cost plus markup is the default since the reseller sets their own prices.
- A price change never rewrites an invoice already issued.

### Admin views

A Filament page lists operations with filters for status and type, shows the redacted payload and error, and offers retry for failed rows. A service-level panel shows the registrar order ID and last-seen expiry. Follow the Announcements and Affiliates extensions for the resource structure.

## Scheduled jobs and reconciliation

Paymenter's own cron job handles invoicing and suspension. The extension adds three scheduled commands, registered from `boot()`.

| Job | Frequency | What it does |
| --- | --- | --- |
| Action poller | Every 5 minutes | Finds operations in `submitted` state, queries the actions search by `eaqid`, and marks them completed or failed. Retries with backoff up to a limit, then flags for admin. |
| Expiry sync | Daily | Pages through `domains/search.json` (`no-of-records`, `page-no`, optional `expiry-date-start` and `expiry-date-end`), reads each order's registry expiry, and updates `rc_expiry`. Reports services whose Paymenter `expires_at` and registrar expiry differ by more than a threshold. |
| Pricing sync | Nightly | Fetches and caches reseller cost prices, then proposes plan price changes. |

### Reconciliation rules

- A service paid but with no `rc_order_id` after the retry limit raises an admin-visible failed operation. The customer sees the service as pending, not active.
- A registrar expiry later than Paymenter's `expires_at` is informational (the domain was renewed elsewhere).
- A registrar expiry earlier than Paymenter's `expires_at` after a renewal operation means the renewal failed silently; mark failed and alert.
- Expiry sync never changes a service's status, only records and reports.

### Queue

All registrar writes run as queued jobs with a unique key per operation, so a worker restart or duplicate dispatch cannot send a second write.

## Error handling, security and operations

### Failure modes

| Failure | Handling |
| --- | --- |
| `status: ERROR` from the API | Typed exception; operation marked failed with the registrar's message; retry only if the call is safe to repeat. |
| Server IP not whitelisted | Connection fails; `testConfig` and the failure alert name this cause. |
| Insufficient reseller funds | Operation fails with a clear admin alert; the service stays pending, and the retry button works after topping up. |
| Domain taken after checkout | Register fails; admin alert; refund or credit path is manual in v1. |
| Timeout on a write | Treat as unknown, not failed: look up the order before any retry, to avoid a double write. |
| TLD-specific requirements missing | Pass `attr-name`/`attr-value` from product config; fail with a message naming the missing attribute. |
| Pending action never completes | Poller escalates after a threshold. |

### Security

- The API key is stored as an encrypted setting and never logged, rendered or included in operation payloads.
- Auth codes are shown once to the owner and never persisted or logged.
- Every customer action checks service ownership server side.
- All registrar writes need an authenticated, authorised user; search is public but rate limited.
- Test and live modes are separate settings. Use the demo account for test mode, since live credentials can perform real operations even at the test address.

### Operations

- Whitelist the server's outbound IP at Settings > API in the ResellerClub panel; changes of host or IP need updating there.
- Run a queue worker and the scheduler; without them nothing registers or renews.
- Log operations without secrets and keep them for audit.
- Keep the reseller funds balance topped up; renewals and registrations fail without it.
- Back up the operations table with the Paymenter database.

## Testing plan and acceptance

### Layers

| Layer | Scope |
| --- | --- |
| Unit | Client request building, response parsing, error mapping, idempotency key generation, markup maths. HTTP is faked. |
| Feature | Paymenter flows with a fake `RegistrarClient`: first payment registers, renewal event renews once, duplicate event does nothing, terminate is a no-op. |
| Contract | Recorded responses from the ResellerClub demo account for each endpoint, replayed in CI. Re-record when an endpoint changes. |
| Manual end to end | Demo account for the full flow, then one real low-cost TLD on live credentials. |

### Required scenarios

1. Register, then renew, then change nameservers on the demo account.
2. Pay a renewal invoice twice (duplicate webhook): one registrar call.
3. Refund a cancelled paid invoice (issue #1416): no second renewal.
4. Let a service run past the termination threshold: the domain survives.
5. Register a name that was just taken: failed operation, admin alert, customer not told it is active.
6. Top-up shortfall: the operation fails clearly and retries after funds are added.
7. Bad key vs non-whitelisted IP: distinct `testConfig` messages.
8. Worker killed mid-job: no duplicate write on restart.

9) Switch registrations off: search shows the notice and makes no availability call, a first invoice paid afterwards fails with the "registrations disabled" reason, and a renewal and a nameserver change on an existing domain still succeed.

### Phase 0 confirmations

Using the demo account, confirm and record the exact parameters for the endpoints in the "To confirm" table: customer signup, add contact, order details, theft protection, lock list, privacy, auth code, DNS and balance. Update the client table with verified links as each is confirmed.

## Milestones, risks and open questions

Work runs in five phases. The MVP is phase 1; the gates are the points where the next phase waits for evidence, not for a date.

&#91;embedded content: roadmap · 5 phases, 3 gates\]

My estimate is about two weeks to the end of phase 1 and four to six weeks for phases 0 to 3, for one developer who knows Laravel. Phase 0 can move it, because it decides how much of the "to confirm" table turns out to be harder than it looks. Phase 4 is optional.

### Risks

| Risk | Why it matters | Mitigation |
| --- | --- | --- |
| Unconfirmed endpoints | Customer, contact, privacy, lock and auth-code calls were not readable in official docs here. | Phase 0 against the demo account, with recorded responses kept for tests. |
| Double renewal | Paymenter has no renewal hook, and a refund can re-run renewal (issue #1416). | Operation key plus the live-expiry guard. |
| Domain deleted by cron | Default termination fires 14 days after a missed payment. | `terminateServer` and `suspendServer` never touch the registrar. |
| API generation change | The portal presents v2 as the rebuilt API for domains. | Client interface; v2 is a second implementation. |
| Reseller funds or IP | Calls fail if the balance is short or the server IP changes. | Clear errors, low-balance alert, runbook entry. |
| Paymenter version drift | Marketplace extensions target 1.4 and 1.5. | Pin the target version, and test on upgrade. |
| TLD-specific rules | Some TLDs need extra attributes or lack privacy or lock. | Per-product attribute config; hide unsupported actions. |

### Open questions

1. **Legacy HTTP API or v2 REST?** This spec assumes legacy for the MVP. Say if v2 should be the first target.
2. **Build or fork?** `autonomiceng/paymenter-domains` (MIT, in development) already has billing and renewal controls for Enom and NameSilo. Adding ResellerClub there avoids rebuilding search and billing, at the cost of tracking someone else's project.
3. **Which Paymenter version are you running?** It decides the extension API and the migration path.
4. **Are transfers in and DNS records part of v1?** They are phase 4 here.
5. **Can a plan use a yearly billing unit?** If not, registration terms use 12 monthly units.
6. **What tolerance does the renewal guard use?** A few days is the proposal.
7. **Which host is live?** The articles show only the test host; confirm the live base URL.

## What the source confirms

Paymenter has no hook that tells a server extension about a renewal, and no native concept of a domain. These findings come from the main repo at commit `ef469ff`.

| Question | Finding |
| --- | --- |
| Renewal hook | None exists. [RenewServiceService](https://github.com/Paymenter/Paymenter/blob/ef469ffb50cb113ceed3cb359aed16b1a7005939/app/Services/Service/RenewServiceService.php) only dispatches `UnsuspendJob` (if suspended) or `CreateJob` (if pending), then sets `expires_at` and `status = active`. A paid renewal invoice never reaches your extension. |
| Where to hook renewal | [ServiceObserver](https://github.com/Paymenter/Paymenter/blob/ef469ffb50cb113ceed3cb359aed16b1a7005939/app/Observers/ServiceObserver.php) fires `App\Events\Service\Updated` on every save. Listen to it in `boot()` and act when `expires_at` changed. |
| First registration | A paid first invoice runs the same service, which finds the service `pending` and dispatches `CreateJob`, which calls `createServer`. So `createServer` is the registration trigger. |
| Cron behaviour | [CronJob](https://github.com/Paymenter/Paymenter/blob/ef469ffb50cb113ceed3cb359aed16b1a7005939/app/Console/Commands/CronJob.php) dispatches `SuspendJob` once a service is 2 days overdue and `TerminateJob` at 14 days (both configurable). It also cancels pending invoices on termination. |
| Storing registrar IDs | Built-in servers use `$service->properties()->updateOrCreate(['key'=>…], ['name'=>…, 'value'=>…])`. Checkout values are also copied into service properties ([Cart.php](https://github.com/Paymenter/Paymenter/blob/ef469ffb50cb113ceed3cb359aed16b1a7005939/app/Livewire/Cart.php), line 193), so the domain name becomes a property. |
| Customer management UI | [Show.php](https://github.com/Paymenter/Paymenter/blob/ef469ffb50cb113ceed3cb359aed16b1a7005939/app/Livewire/Services/Show.php) only calls `getActions` when the service is active, and sorts results into buttons, views and text fields. |
| Native domain support | None in the models or Livewire components. |
| Extension structure | Servers live in `extensions/Servers/<Name>/<Name>.php` with `getConfig`, `testConfig`, `getProductConfig` and `getCheckoutConfig`. The [docs](https://paymenter.org/development/extensions/server) say server extensions also support everything in the [extensions overview](https://paymenter.org/development/extensions/), so `boot()`, routes, views and migrations are available. |
| Org layout | The [GitHub org](https://github.com/Paymenter) has four repos. The old Extensions repo is archived (June 2025) in favour of the `extensions/` folder in the main repo. |

## What this changes in the plan

Five design decisions follow from the source.

1. **Make terminate a no-op.** At 14 days overdue Paymenter calls `terminateServer`. For a domain that must never delete or release anything. Treat suspend and unsuspend as registrar lock and unlock, or as no-ops, and let the registrar's own expiry and grace period apply.
2. **Add a renewal listener in `boot()`.** On `Service\Updated`, check that the product's server is yours and that `expires_at` changed. Then fetch the live order details from ResellerClub and call `renew.json` with the registrar's own expiry as `exp-date`, in epoch format. Don't use Paymenter's date.
3. **Make renewal idempotent.** Open bug [#1416](https://github.com/Paymenter/Paymenter/issues/1416) shows that refunding a cancelled paid invoice re-renews the service. Without a guard, that would pay for a second registrar renewal. Skip the call if the registrar expiry is already at or beyond the target date.
4. **Build search as your own route and Livewire component**, registered in `boot()`. Pass the chosen name into the product's checkout config. `getCheckoutConfig` receives the product, current values and settings, so one product per TLD still fits.
5. **Copy `Others/Announcements` for structure.** It is the closest in-repo example of routes, views, migrations and Livewire.

## Still unverified

Two points need testing before the design is final.

- Whether checkout `validation` accepts a custom rule object, so an availability check could run at checkout. `Checkout.php` merges arrays of rules, so it probably works.
- Whether plans support a yearly billing unit. This matters for domain pricing and was not checked.

## An alternative to building from scratch

[paymenter-domains](https://github.com/autonomiceng/paymenter-domains) is an MIT-licensed, in-development native domain registrar extension for Enom and NameSilo. A merged [pull request](https://github.com/autonomiceng/paymenter-domains/pull/290) there covers owner renewal controls, auto-pay renewal invoices and a registrar provider layer. Only the pull-request page could be read, not its code. Adding ResellerClub as a third provider there may be quicker than a fresh build.

Next step: read that repo's provider interface to see how much of the plan it already covers, or scaffold the extension skeleton and the ResellerClub client.
