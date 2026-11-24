# Phase 0 checklist: what is proven and what is still open

Checked on a real Paymenter install and ResellerClub's demo account (test host). Anything left unticked needs a real, funded, *active* order, which the demo account has never had, so it is also the list to walk through on the first real order. Remove the `UNCONFIRMED` markers in `LegacyHttpClient.php` as each is confirmed.

## Proven

- [x] `customers/details.json` and `customers/signup.json` (password must be 8 to 15 alphanumeric characters).
- [x] `contacts/add.json`, including the contact type per extension (`UkContact` for `.uk`, `.co.uk`, `.org.uk`, `.me.uk`; otherwise `Contact`).
- [x] `domains/register.json` response (`entityid`, `eaqid`, `actionstatus`); errors come back as `{"status":"error","error":...}` with HTTP 200 as well as `{"status":"ERROR","message":...}`.
- [x] `domains/available.json` on `domaincheck.httpapi.com` (can be slow: the client gives up after 10 seconds).
- [x] `domains/orderid.json` answers an ordinary API error for an unknown domain.
- [x] `actions/search-current.json` row shape (`eaqid`, `actionstatus`, `actionstatusdesc`); an unknown id answers HTTP 500 "No record found".
- [x] `domains/search.json` rows keyed `"1"`... with `recsonpage` / `recsindb`.
- [x] `products/reseller-cost-price.json`: `.com` is `domcno` and a few others have non-`dot<tld>` keys (aliases are in `CostTable`).
- [x] Yearly billing unit for plans.
- [x] Paymenter side: server hooks, `getActions` view actions, the `permissions` event, Filament action forms, the `/domains` route (not shadowed by a category), checkout prefill through `?config[...]`, `pages.home` hook, cart item events (created, updated, deleted), extension upload and upgrade (`installed` / `upgraded`).
- [x] The demo registry accepts `ns1.domain.com` but rejects some third-party nameservers; the live registry needs real, resolvable ones.
- [x] An order the reseller cannot pay for is accepted and **held**: it stays inactive ("locked in processing", action status `InvoicePaid`) and the reason is only in the order details. The extension treats this as a normal waiting state (see the README).

## Still open (needs a real, funded, active order)

- [ ] Live API base URL (`LegacyHttpClient::LIVE_BASE`, documented as `https://httpapi.com/api/`).
- [ ] `domains/details.json` field names once the order is active: `endtime` (epoch or text?), `nsN`, `currentstatus`, `domsecret` (transfer code), the lock status, the privacy fields.
- [ ] Where the "insufficient funds" text sits in the details of a held order (the extension searches the whole response).
- [ ] The success and failure `actionstatus` strings (`ActionStatus::isCompleted` / `isFailed`); only `InvoicePaid` has been seen.
- [ ] `invoice-option=NoInvoice` debits the reseller balance without a customer invoice.
- [ ] `domains/delete.json` (cancel), `domains/enable-theft-protection.json` / `disable-theft-protection.json` (transfer lock) and `domains/modify-privacy-protection.json` (WHOIS privacy): request shape and responses.
- [ ] That a held order completes by itself once the balance is topped up (the watcher assumes so and keeps polling).
- [ ] `domains/transfer.json` for a real transfer, and what a rejected transfer code looks like.
- [ ] A full renewal cycle (paid renewal invoice, then the registrar renewal and expiry).

## Known core behaviour

- `RenewServiceService` sets the service `active` right after dispatching `CreateJob`. With a real queue worker, `createServer` puts a service that is not live (failed, held or still processing) back to `pending`; with the `sync` driver the core's later `active` write wins, so run a queue worker.
- `CreateJob` sends the "Service activated" email whenever `createServer` returns normally, so for a held order `createServer` deliberately throws (one entry under Failed Jobs, safe to mark resolved) and the email is sent when the order really activates.
