# Phase 0 checklist (not verifiable offline)

Confirm on the ResellerClub demo account and in a real Paymenter install, then remove the UNCONFIRMED markers:

- [ ] Live API base URL (`LegacyHttpClient::LIVE_BASE`).
- [ ] `customers/details.json` + `customers/signup.json` parameters and response shape.
- [ ] `contacts/add.json` required parameters.
- [ ] `domains/details.json` field names (`endtime`, `nsN`); `ping()` read used by `testConfig`.
- [ ] `actions/search-current.json` response keys and the list of `actionstatus` values (`ActionStatus::isCompleted/isFailed`).
- [ ] Error message wording for bad key / IP / funds / taken (`LegacyHttpClient::classify`); the 403 IP heuristic.
- [ ] `invoice-option=NoInvoice` debits reseller balance without a customer invoice.
- [ ] Paymenter: base `Server` hook signatures, `ExtensionHelper::runMigrations/rollbackMigrations` path, `getActions` function-call convention, how console commands are registered, property/`wasChanged` semantics on `Service\Updated`.
- [x] Yearly billing unit for plans: supported (`plans.billing_unit` enum includes `year`; `Plan::billingDuration`). Renewal tolerance (default 3 days) still to confirm.
- [ ] `domains/search.json` response keys used by `searchOrders` (`orders.orderid`, `entity.description`, `orders.endtime`, `recsindb`).
- [ ] `reseller-cost-price.json` shape assumed by `CostTable::parse` (`<product>.addnewdomain.<years>`; TLD from a `tld` field or a `dot<tld>` key). If real keys differ, only `CostTable` changes.
- [ ] Paymenter (needs a running install): console command registration via `Illuminate\Console\Application::starting` in `boot()`; Filament action `schema()` forms and the `permissions` event on the operations page; `/domains` route not shadowed by a category slug; checkout prefill via `?config[domain_name]=`.
- [ ] Phase 2 endpoints: theft protection, lock list, privacy, auth code. Phase 4: transfer in, DNS.

## Verified against Paymenter source (shallow clone in ../../../paymenter-src)
- Extension class must live at `extensions/Servers/ResellerClub/ResellerClub.php` (PSR-4 `Paymenter\Extensions\` => `extensions/`).
- Hook signatures, `Service\Updated` (fired from ServiceObserver::updated), `ExtensionHelper::runMigrations(base_path-relative)`, view-type actions (`getView` via the action's `function`) and `ExtensionMeta` match the code.

## Known core behaviour
- `RenewServiceService` sets the service `active` right after dispatching `CreateJob`. With a real queue worker, `createServer` puts a failed registration back to `pending`; with the `sync` driver the core's later `active` write wins, so run a queue worker.
