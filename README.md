# Paymenter ResellerClub domains

Sell domain names from a [Paymenter](https://paymenter.org) billing site using a [ResellerClub](https://www.resellerclub.com) reseller account, WHMCS-style:
customers can buy a domain on its own, or add one (register, transfer in, or "I already have one") when they order hosting.
Each domain is its own service, so a customer can cancel the domain and keep the hosting, or the other way round.

Built and tested against Paymenter running Laravel 13, Filament 5 and Livewire 4, with Plesk hosting.

## What is in this repo

| Folder | Paymenter type | What it is |
| --- | --- | --- |
| `extensions/Servers/ResellerClub` | Server extension | The domain engine: registration, transfers, renewals, price sync, admin pages, client-area tabs, home page search. |
| `extensions/Servers/PleskDomains` | Server extension | The built-in Plesk module plus a "register / transfer / use my own domain" step at hosting checkout. Everything Plesk does (create, suspend, unsuspend, terminate) is inherited unchanged. |
| `other-extensions/InvoiceDomains` | Other extension | Puts the service's domain on invoice lines ("Solo - example.co.uk"). Optional. |

## What it does

**For customers**
- Home page: a "Domains" card and a domain search box with the extensions on sale and their yearly price.
- `/domains`: availability search. Available names get a Buy button; taken names get a Transfer button.
- Hosting checkout: choose *Register a new domain*, *Transfer my domain to you* (with the transfer code) or *I'll use a domain I already own*. A registered or transferred domain is added to the cart as a separate domain service. Availability is checked again before the order is accepted.
- Registrant details come from the customer's Paymenter account (WHMCS style), so checkout only asks for the domain.
- Client area, per domain: **Nameservers** (edit, or reset to the defaults) and **Transfer away** (transfer lock on/off and the transfer/auth code, shown on request, never stored or logged).
- Cancelling a domain tells ResellerClub: the registration is deleted when it is still inside the registry's grace period, otherwise it is left to expire (it is never auto-renewed at the registrar).

**For admins** (Admin > Domain setup / Domain operations)
- One click creates a product per extension (with a price for every registration term) and syncs prices from your ResellerClub cost prices plus a markup (percentage or fixed, optional per-product override and exchange rate). Prices can be applied automatically each night or reviewed first.
- Every registrar action (register, transfer, renew, nameservers, cancel) is an *operation* with status, attempts, the registrar's order and the error text. Failed registrations and renewals can be retried from the list.
- An email goes to the system email address when a paid domain order fails to register (for example the reseller balance is too low), so nothing sits unnoticed.
- Permissions are separate (`admin.resellerclub.operations.view`, `.retry`, `.manage`).

## How it is built to be safe on a live billing site

- Registrar calls are idempotent: an operation key stops a double click, a retry or a timeout from ordering a domain twice, and a timed-out registration looks the domain up before it retries.
- Suspending or terminating a *hosting* service never touches the registrar, and the nightly cron cannot delete a domain.
- Paymenter runs the whole nightly cron in one database transaction and boots every server extension on every request, so everything here that could throw is contained: `boot()`, scheduled jobs, listeners, the home page block and the cancel path all swallow and report their own errors, and reporting goes through a wrapper that cannot throw even if the log file is unwritable.
- UK names (`.uk`, `.co.uk`, `.org.uk`, `.me.uk`) get the contact type ResellerClub requires.
- Availability lookups give up after 10 seconds so checkout never hangs; the customer sees "could not check right now".
- Each service's domain page checks ownership and status on the server for every action; order ids and customer ids never come from the browser.

## Requirements

- Paymenter with a working queue worker and scheduler (domain registration, polling and nightly sync run there).
- A ResellerClub reseller account and API key. The server's outgoing IP must be allowed in ResellerClub's API settings, and ResellerClub's Cloudflare must not be blocking it.
- Plesk hosting if you want the checkout domain step (the `PleskDomains` module).

## Install

Build the upload zips (needs Python 3):

```
python build.py
```

This writes `dist/ResellerClub.zip`, `dist/PleskDomains.zip` and `dist/InvoiceDomains.zip`. Upload each in **Admin > Extensions > Available Extensions > Upload Extension**, then:

1. **Admin > Extensions > Servers**: add a server using *ResellerClub* and fill in the settings below. Use **Test connection** on the Domain setup page.
2. Create a category for domains (for example "Domains"), then **Admin > Domain setup > Create TLD products**. Standard extensions are pre-filled; products start **hidden**.
3. Check the prices, then untick *Hide product* on the extensions you want to sell.
4. To offer the domain step with hosting, add a server using *Plesk + Domains* (leave its connection fields blank to reuse your existing Plesk server) and switch your hosting products to it on the product's *Server* tab, re-entering each product's Plesk plan name (changing the server clears it).

After uploading a new version, restart the queue worker if jobs keep running old code.

## Server settings (ResellerClub)

| Setting | Meaning |
| --- | --- |
| Reseller ID / API key | Your ResellerClub `auth-userid` and API key. |
| Live mode | Off = ResellerClub's demo (test) host. On = the live host. |
| Live API base URL | Defaults to `https://httpapi.com/api/`. |
| Allow new registrations / transfers in | Master switches. With registrations off, hosting checkout only offers "use my own domain" and the home page block disappears. |
| Testing: offer hidden domain products | Lets hidden TLD products be sold at hosting checkout. Keep off in production. |
| Default nameserver 1 / 2 | Given to every new domain (use your Plesk/DNS nameservers). |
| Renewal window / tolerance | The registrar's own expiry is always re-read before a renewal. If it already covers the new term (within the tolerance in days) nothing is bought, so a repeated run cannot renew twice. If it is still further away than the window (days), the renewal is held back as a failed operation instead of spending funds, because the service date probably moved for another reason (refund, manual edit). |
| Markup type / value / exchange rate | How selling prices are worked out from ResellerClub cost prices. |
| Auto-apply nightly price changes | Off = price changes are proposed on the Domain setup page for review. |

Per-product settings: TLD, years, privacy default, TLD attributes (`name=value` per line, for extensions that need extra data) and a markup override.

## Going live checklist

1. Switch the server to your live reseller ID and API key and tick **Live mode**.
2. Put money in the ResellerClub account (registrations are paid from its balance; without it they fail with a funds error and show as failed operations to retry).
3. Run **Sync prices now** and review or apply the prices (sandbox prices are demo values).
4. Unhide the extensions you want to sell, and place one real test order.

## Known limits

- Some ResellerClub endpoints could not be confirmed against a real order and are marked `UNCONFIRMED` in `LegacyHttpClient.php`: the order-details field names (`endtime`, `nsN`, `domsecret`, lock status), `domains/delete.json`, and the theft-protection (lock) endpoints. Check them on the first real order.
- Premium domains are not sold. DNS record editing is not included (DNS is expected to live on your hosting/DNS nameservers).
- Only the UK extensions use a special contact type; other country-specific registry rules go through the *TLD attributes* setting.
- Editing a hosting item in the cart does not re-sync its paired domain item.

## Troubleshooting

- **"Blocked by ResellerClub's Cloudflare firewall"**: the API's Cloudflare refused this server's IP before the API saw the request. The message includes the IP and Ray ID; send both to ResellerClub support. The API key whitelist alone does not help.
- **"server IP is probably not whitelisted"**: add the IP under Settings > API in ResellerClub.
- **Extension upload shows "Failed to upload extension" with a log "Permission denied" message**: the web user cannot write the daily Laravel log file (often because a root cron job created it). The upload itself succeeds. Fix it by logging to stderr (`LOG_CHANNEL=stderr`) or fixing the log folder ownership.
- **A registration is "failed" with a funds error**: top up the reseller balance and press Retry on the operation.

## Development

```
cd extensions/Servers/ResellerClub
composer install
vendor/bin/phpunit --exclude-testsuite Live
```

The unit tests run the engine against a fake registrar (`tests/FakeRegistrarClient.php`) and a faked HTTP transport, so they need no network. The `Live` suite talks to ResellerClub's test host and needs credentials in the environment.

## Licence

MIT with the [Commons Clause](https://commonsclause.com/): you may use and modify this software, including in your own business, but you may not **sell** it, or sell a product or service whose value comes mainly from it. See [LICENSE](LICENSE).
