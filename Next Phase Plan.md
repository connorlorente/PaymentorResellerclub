# Next phase: finish phase 3, then phase 2

Handoff plan for a new session. Read the references below first.

## Reference paths

| What | Path |
| --- | --- |
| Original spec (source of truth) | `c:\Users\Connor\Desktop\ResellerClub\Resellerclub Plan.md` |
| Previous implementation plan (phases 1-3 standalone) | `C:\Users\Connor\.claude\plans\implement-resellerclub-plan-md-harmonic-stroustrup.md` |
| Extension root (all code lives here) | `c:\Users\Connor\Desktop\ResellerClub\extensions\Servers\ResellerClub\` |
| Main extension class | `extensions\Servers\ResellerClub\ResellerClub.php` |
| Registrar client + interface + DTOs | `extensions\Servers\ResellerClub\Registrar\` (`LegacyHttpClient.php`, `RegistrarClient.php`, `Dto.php`, `RegistrarException.php`) |
| Framework-free logic (renewal, registration, nameservers, poller, markup, redaction) | `extensions\Servers\ResellerClub\Core\` |
| Eloquent model/store, migration | `Models\`, `database\migrations\2026_10_27_000000_create_ext_resellerclub_operations_table.php` |
| Renewal listener, jobs, Livewire nameservers, rules | `Listeners\`, `Jobs\`, `Livewire\`, `Rules\`, `resources\views\` |
| Tests + fake client | `extensions\Servers\ResellerClub\tests\` (`FakeRegistrarClient.php`, `Unit\`, `Feature\`) |
| Open confirmations checklist | `extensions\Servers\ResellerClub\PHASE0.md` |
| Paymenter source (shallow clone, delete when done) | `c:\Users\Connor\Desktop\ResellerClub\paymenter-src\` |
| Structure to copy for routes/Livewire/Filament | `paymenter-src\extensions\Others\Announcements\` (`Announcements.php`, `Admin\Resources\AnnouncementResource*`, `routes\`, `Livewire\`) |
| Core files that matter | `paymenter-src\app\Helpers\ExtensionHelper.php`, `app\Services\Service\RenewServiceService.php`, `app\Observers\ServiceObserver.php`, `app\Models\Plan.php` (`billing_unit` supports `year`) |

## Current state
Built and unrun: client, core services, listener, jobs, migration, nameserver view, registrations-off rule, 5-minute action poller. **No test has ever been executed** (PHP not installed). Not built: expiry/pricing sync, `/domains` search, TLD product command, Filament operations page, phase 2 actions, availability re-check at checkout.

Known core behaviour: `RenewServiceService` marks a service active right after queueing `CreateJob`, so a failed registration still looks active to the customer (no `rc_order_id`, no actions). The failed operation is the admin-visible signal.

## Steps

**0. Get tests running (blocks everything).** Install PHP 8.3 + composer (try `winget`). In the extension root run `composer install && vendor/bin/phpunit`. Fix failures. Add `php -l` over all files; PHPStan against `paymenter-src` if feasible.

**1. Admin visibility.**
- `Admin/Resources/OperationResource` (copy Announcements structure): status/type filters, redacted payload and error, retry action on failed rows (reset to `queued`, re-dispatch the matching job).
- Add `Jobs/RegisterDomainJob` so a failed registration can be retried (today registration only runs via Paymenter's `CreateJob`).
- Service panel showing `rc_order_id` and last-seen expiry.
- Register permissions via the `permissions` event, as Announcements does.

**2. Scheduled sync.**
- Expiry sync (daily): page `domains/search.json`, update `rc_expiry`, apply reconciliation rules from the spec ("Reconciliation rules"). Never changes service status.
- Pricing sync (nightly): cache `reseller-cost-price.json`, propose plan prices via `Core\Markup::price`. Auto-apply setting defaults **off**. Never touch issued invoices.
- Add markup settings (percent/fixed, per-product override). Tests for both.

**3. TLD products.** Admin action creating one product per TLD with yearly-unit plans for 1-10 years (read Product and Plan models first). TLD in product config; quantity disabled.

**4. Search page + checkout.** Livewire component + `/domains` route (as Announcements registers routes/views). Only TLDs with an enabled product; availability cached 60 s; notice instead of results when registrations are off; hide premium; link to product checkout with the domain prefilled. Add an availability re-check as a custom checkout validation rule (verify against `paymenter-src\app\Livewire\Products\Checkout.php`; `Rules\RegistrationsOpen` already uses a rule object).

**5. Phase 2 customer actions** (depends on step 6). Client methods for theft protection, privacy, transfer code; extra tabs in the existing view action. Transfer code shown once, never logged or stored. Hide controls where the registrar refuses.

**6. Demo-account confirmation (needs ResellerClub demo account, server IP whitelisted).** Work through `PHASE0.md`; record real responses as fixtures; add contract tests that replay them.

Steps 0-4 are doable offline. Step 5 and final client correctness need step 6.

## Open decisions
1. Is there a ResellerClub demo account for step 6?
2. Pricing auto-apply default: off (recommended) or on?
