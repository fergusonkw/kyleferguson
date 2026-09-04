# Billing System Architecture Plan

Companion to [`billing-policy.md`](billing-policy.md). The policy doc captures rules and decisions; this doc captures structure and implementation order. Update this file as decisions change during the build.

## Stack alignment

- Laravel 13, PHP 8.3, Tailwind v4 (the admin-starter template targets these versions; CLAUDE.md / Boost guidelines reflect the previous baseline of Laravel 12 / PHP 8.2 and will be refreshed)
- Admin UI lives under `resources/views/admin-v2/`, extends `admin-v2.layouts.vertical`
- All UI must reuse existing `x-admin-v2.*` Blade components before introducing new markup
- No jQuery, no Bootstrap — vanilla JS, `fetch`, Preline overlays, native DataTables, Choices.js, Lucide icons
- Tests are PHPUnit feature tests (per project rule); every change ships with tests
- Pint run before finalizing (`vendor/bin/pint --dirty`)

## Data model

### Business & identity

- **`businesses`** — name, legal_name, address, contact_email, logo_path, brand_primary_color, brand_secondary_color, invoice_template_view, email_template_view, invoice_number_prefix, invoice_number_sequence, default_currency, supported_currencies (JSON), fx_source, tax_registered_from (nullable), notification_email, daily_reminder_time, late_fee_terms (free text shown on invoice footer)
- **`clients`** — business_id, name, contact_name, contact_email, billing_address, billing_currency (defaults to business default; constrained to business's supported_currencies), status, default_markup_type, default_markup_value, notes
- **`projects`** — client_id, name, do_project_uuid (nullable), markup_type (nullable → falls back to client), markup_value (nullable), status, terminated_at

### Cost providers (abstract)

- **`cost_providers`** — business_id, slug (`digitalocean`, future `forge`, etc.), display_name, credentials (encrypted), enabled, last_synced_at, last_sync_status
- **`provider_resources`** — cost_provider_id, project_id (nullable until attributed), provider_resource_id, resource_type, name, first_seen_at, last_seen_at
- **`resource_assignments`** — provider_resource_id, project_id, observed_from, observed_to (nullable = current). Effective-dated history for audits.

### Cost ingestion

- **`cost_providers`** (Phase 1 table, extended in Phase 2) — adds `config` (JSON, **not** encrypted — provider-specific settings such as SMTP2GO region, `monthly_fee`, `fee_currency`) and `client_id` (nullable FK, `nullOnDelete`). `client_id` is the explicit 1:1 link for account-per-client providers (SMTP2GO); it is null for account-per-business providers (DigitalOcean, Laravel Cloud). Encrypted `credentials` still holds only the API key/token.
- **`provider_billing_payloads`** — cost_provider_id, period (YYYY-MM), source_key (nullable — e.g. DO invoice uuid, SMTP2GO cycle id), raw_payload (JSON), content_hash (sha256), fetched_at — unique on (cost_provider_id, period, content_hash)
- **`cost_line_items`** — cost_provider_id, provider_resource_id (nullable for unattributable), project_id (nullable), period, category (enum), description, source_amount + source_currency (as reported by the provider), usd_amount, usd_tax (canonical USD cost basis), source_payload_id (nullable FK), source_reference (nullable), line_hash (deterministic canonical string, unique per (cost_provider_id, period) — the idempotency key), attributed_at (nullable), derived_at

### FX

- **`fx_rates`** — currency_from, currency_to, period, rate, source (`bank_of_canada`, future others), fetched_at — unique on (from, to, period, source). Supports arbitrary currency pairs so a USD-billed client and a CAD-billed client are both handled from the same USD cost basis. `USD→USD` is a hard-coded 1.0 no-op; `CAD→USD` is derived by inverting the published `USD→CAD` rate.

### Invoicing

- **`invoices`** — business_id, client_id, invoice_number, period_start, period_end, status (`draft|approved|sent|partially_paid|paid|void`), issue_currency (e.g. `CAD`, `USD`), subtotal, total, fx_rate_snapshot (rate from USD costs → issue_currency), fx_rate_source, fx_rate_period, template_view_snapshot, email_template_view_snapshot, late_fee_terms_snapshot, approved_at, sent_at, voided_at, pdf_path, hosted_view_token
- **`invoice_lines`** — invoice_id, parent_id (nullable), project_id (nullable), label, line_type (`hosting|recurring|manual|adjustment|discount|credit`), amount (in invoice's `issue_currency`), source_reference (nullable, e.g. `cost_line_items:123`), display_order
- **`recurring_line_templates`** — client_id (nullable) or project_id (nullable), label, amount, currency (must match the client's billing_currency at apply time, else surfaced as a config error), cadence, active_from, active_to (nullable)

### Payments

- **`payments`** — invoice_id, amount (in invoice's `issue_currency`), received_at, method (`etransfer|cheque|cash|stripe|interac|other`), reference, notes, recorded_by_user_id, deleted_at (soft delete for voids)

### Audit

- Reuse existing `AuditLog` for: invoice status transitions, manual line edits, payment record/edit/void, token rotation, markup config changes.

## Services layer (`app/Services/Billing/`)

### Provider integration

- **`DigitalOcean\Client`** — HTTP wrapper. Read-only token. Retry with backoff. Rate-limit aware. *(Phase 1, in place.)*
- **`DigitalOcean\ProjectSync`** — pulls projects + resources, writes `provider_resources`, closes/opens `resource_assignments` on changes. *(Phase 1, in place; scheduled sync paused — see Jobs.)*
- **`DigitalOcean\BillingSync`** — **deferred.** DO billing ingestion is parked while Laravel Cloud is evaluated as the hosting platform. The adapter contracts below are shaped so DO — or a `LaravelCloud\BillingSync` — drops in later without disturbing SMTP2GO.
- **`Smtp2go\Client`** — HTTP wrapper for the SMTP2GO stats API. Regional base URL (`config.region`), `X-Smtp2go-Api-Key` header, retry/backoff mirroring `DigitalOcean\Client`.
- **`Smtp2go\BillingSync`** — SMTP2GO exposes **no cost/invoice API**, only usage. Per period it: ensures one synthetic `provider_resources` row for the account (`resource_type = 'subscription'`), auto-seeds its `resource_assignments` row to the linked client's sole active project (or leaves it unattributed and flagged if the client has 0 or >1 active projects), pulls `/stats/email_cycle` and stores it as a `provider_billing_payloads` row, and writes **one** `cost_line_items` row (`category = Email`, `usd_amount` = `config.monthly_fee` converted from `config.fee_currency`, cycle usage figures into `description` + metadata). Idempotent on `line_hash`.
- **`Smtp2go\Dto\Smtp2goCycle`** — typed `/stats/email_cycle` response (`cycle_start`, `cycle_end`, `cycle_used`, `cycle_remaining`, `cycle_max`).

### Cross-provider

- **`Contracts\ResourceSyncAdapter`** (`syncProjectsAndResources()`), **`Contracts\BillingSyncAdapter`** (`syncBilling(CostProvider, string $period): int`), **`Contracts\CredentialValidator`** (`validateCredentials()`) — the current single `CostProviderAdapter` interface is split into these three. A provider implements only what it supports (DO: resource + credential; SMTP2GO: billing + credential).
- **`ProviderAdapterRegistry`** — resolves a `CostProviderSlug` to its adapter instances; an unsupported capability throws `UnsupportedProviderCapability`. Replaces the `if ($slug === DigitalOcean)` branches in `CostProviderController` and `routes/console.php`. Its `supports*()` probes also drive which sync buttons a provider row renders.
- **`BillingPeriod`** — periods are calendar months (`YYYY-MM`). `openPeriods()` returns the current month, plus the previous month for the first 5 days while a provider settles it; validation, start/end, and display labels live here too.
- Credentials keep each provider's own vocabulary inside the encrypted blob (`token` for DigitalOcean, `api_key` for SMTP2GO) so adapters read what their API documents.
- **`CostAttributor`** — for a `(business, period)`: sets each `cost_line_items.project_id` from the `resource_assignments` row covering the period end; account-level lines (`category` not attributable, e.g. `Overhead`) are never attributed; lines whose resource is unmapped stay `project_id = null` and are surfaced for reconciliation. Sets `attributed_at`. Idempotent — an unchanged attribution is not re-saved.

  **First-assignment fallback.** A resource whose assignment history begins *after* the period was not yet tracked rather than moved, so its earliest assignment is used instead of leaving the cost permanently unattributed — this is what makes backfilling a period work. A resource that genuinely moved is still governed by the period-end rule, and one explicitly *unassigned* during the period is not back-filled.
- **`FxRateService`** — `rateFor($from, $to, $period): float` for any pair needed (Bank of Canada Valet API monthly average as primary source). `USD→USD` = 1.0; `CAD→USD` by inversion; cross via CAD for exotic pairs. Caches in `fx_rates`. Missing data throws a typed `FxRateUnavailableException`.

### Invoicing

- **`InvoiceBuilder`** — for a given (business, client, period): determines the issue currency (from client.billing_currency) → looks up FX rate (USD cost basis → issue currency) → aggregates attributed costs by project → applies project's markup → adds recurring line templates active during the period → renders the late-fee terms snapshot for the footer → creates `invoice` (draft) + `invoice_lines`. Idempotent on (business, client, period).
- **`InvoiceApprover`** — handles status transitions (`draft → approved → sent`). Triggers PDF generation + email send. Refuses illegal transitions.
- **`InvoicePdfRenderer`** — picks `template_view_snapshot` (or business default at issue time), renders to PDF with business branding (logo, colors), includes the late-fee terms snapshot in the footer, writes to storage, returns path.
- **`PaymentRecorder`** — records/edits/voids payments. Recomputes invoice status. Writes to `AuditLog`.
- **`InvoiceNumberAllocator`** — atomic per-business sequence with prefix.

### Reporting & notifications

- **`ReconciliationReporter`** — computes dashboard tiles: attributed cost by client/project, unattributed resources + cost, overhead, and a provider-reported-vs-attributed **cost gap**. Gap computation is gated on `CostProviderSlug::reportsAuthoritativeTotal()` — true only for providers that independently report what they billed. With only SMTP2GO connected, both the reported total and the gap are `null` and the UI renders `—`, rather than a zero that would look like a clean reconciliation. Also exposes `trailingCost()`, a per-period cost series standing in for the trailing-12-month revenue gauge until invoices exist (Phase 3), alongside the pending-drafts tile.
- **`OperatorNotifier`** — emits the `InvoiceReadyForReview`, `InvoiceGenerationNeedsAttention`, and `DraftReminderDigest` mailables. *(Phase 3.)*
- **`RestatementDetector`** — diffs fresh provider payloads vs. stored; queues adjustment lines onto the next open draft for affected clients. *(Phase 4; relevant once a usage-based provider is connected.)*

## Jobs & scheduling

All jobs implement `ShouldQueue` and are idempotent. Scheduled in `routes/console.php`.

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SyncProviderResourcesJob` | **Paused** (was daily 02:00) | Generalized rename of `SyncDigitalOceanProjectsJob`; routes via `ProviderAdapterRegistry`. Schedule entry commented out while DO is parked — re-enable when a resource-sync provider is active. |
| `SyncProviderBillingJob` | Daily 03:00, for current + previous period | Routes to the provider's `BillingSyncAdapter`; stores payloads, derives `cost_line_items` |
| `AttributeCostsJob` | Daily 03:45, per business | Runs `CostAttributor` for the open periods |
| `FetchFxRateJob` | 1st of month 01:00, one job per non-USD currency clients actually bill in | Warms the Bank of Canada monthly average for the closed month so invoice generation is not the first thing to discover a rate is missing |
| `ReconciliationAlertJob` | Daily 08:00, per business | `Mail\Billing\ReconciliationAlert` to the operator when unattributed resources exist or the cost gap is non-zero |

Phase 3 adds `GenerateMonthlyDraftsJob` and `DraftReminderDigestJob`. Failure handling for those (detect "expected artifact did not appear" → `InvoiceGenerationNeedsAttention`) lands with Phase 3.

## Admin UI

All views under `resources/views/admin-v2/billing/`. Extend `admin-v2.layouts.vertical`. Use existing `x-admin-v2.*` components throughout (`card`, `datatable`, `form.*`, `modal`, `offcanvas`, `button`, `alert`, `status-badge`, `stat-card`, `page-title`).

| Route | Purpose |
|-------|---------|
| `/admin/billing` | Dashboard: tiles (per business), pending drafts list, trailing-12mo gauges |
| `/admin/billing/businesses` | CRUD businesses + per-business config |
| `/admin/billing/clients` | CRUD clients (scoped by business switcher) |
| `/admin/billing/projects` | CRUD projects, link to DO project, markup config |
| `/admin/billing/cost-providers` | Manage provider credentials + `config` (SMTP2GO: API key, region, monthly fee/currency, client link), view sync status, trigger manual resource / billing sync |
| `/admin/billing/invoices` | List with filters (business, client, status) |
| `/admin/billing/invoices/{invoice}` | Detail: lines, edit manual/discount/credit/adjustment lines, approve, send, record payment |
| `/admin/billing/reconciliation` | Cost-line-item table (filter by project / category), unattributed resources, cost gap drilldown, restatement history |
| `/invoices/{token}` (public) | Hosted invoice view (signed URL), payment banner |

## Mailables

- `InvoiceReadyForReview` — to operator when draft generated
- `InvoiceGenerationNeedsAttention` — to operator on failure / missing inputs
- `DraftReminderDigest` — to operator daily, lists unapproved drafts
- `ClientInvoiceMail` — to client on approval; PDF attached + signed URL to hosted view. **Branded per business**: uses `email_template_view_snapshot` (a Blade Mailable template) so each business has its own header/logo/colors/footer. Default template lives at `resources/views/emails/invoices/default.blade.php`; per-business templates can override.

## Testing strategy

PHPUnit feature tests (per project rules). Most logic exercised via service-level feature tests; HTTP layer tested where it adds value (form validation, signed URL access).

Required coverage:

- `ProjectSync`: happy path, unchanged-sync no-op, resource moved between projects (closes/opens assignment row) *(Phase 1, done)*
- `Smtp2go\BillingSync`: credential validation (200 / 400); synthetic subscription resource created once and idempotent; one `cost_line_items` row per period at `config.monthly_fee`; non-USD fee converted via `FxRateService`; `/stats/email_cycle` payload stored; usage figures land in metadata; auto-assignment to the client's sole active project, and the 0/>1-project flagged case
- `ProviderAdapterRegistry`: resolves each slug's adapters; unknown slug throws; `SyncProviderBillingJob` routes to the right adapter; disabled provider skipped
- `CostAttributor`: attributed via current assignment, default-project / unmapped (unattributed), non-attributable category never attributed, mid-period move attributes to the period-end assignment, idempotent re-run
- `InvoiceBuilder`: each markup type (percent, fixed, hybrid, passthrough); with/without recurring items; idempotency on re-run
- `InvoiceApprover`: legal and illegal status transitions
- `InvoicePdfRenderer`: picks correct per-business template
- `PaymentRecorder`: partial → partial → paid progression; overpayment surfaces as next-draft credit; void recomputes status
- `FxRateService`: `USD→USD` = 1.0; mocked Bank of Canada Valet response → monthly average computed + cached; second call served from cache; `CAD→USD` inversion; missing data → `FxRateUnavailableException`
- Tax: invoice generation while unregistered (no tax line); pre-set `tax_registered_from` to test the registered branch even before it's used in production
- Multi-currency: a USD-billed client and a CAD-billed client in the same period both produce correct invoices from the shared USD cost basis
- Email branding: `ClientInvoiceMail` renders the correct per-business template (header, logo, colors, footer)
- Hosted view: valid signed URL renders, expired/invalid token denied
- Notifications: ready-for-review fires on draft generation; daily reminder digest fires only for unapproved drafts > 1 day old
- Trailing-12mo threshold warning fires at configurable percentage

Fixtures: `tests/Fixtures/smtp2go/email_cycle.json`, `tests/Fixtures/bankofcanada/fxusdcad.json`. (DO API fixtures return when `DigitalOcean\BillingSync` is un-parked.)

## Phasing

Each phase is independently useful and ships with its own tests.

### Phase 1 — Foundation

- `businesses`, `clients`, `cost_providers`, `projects` tables + models + factories + seeders
- Encrypted DO token storage
- Business switcher in admin UI
- CRUD for businesses, clients, projects, providers
- `DigitalOcean\Client` + `ProjectSync` service
- `SyncDigitalOceanProjectsJob` + scheduled
- UI: link DO projects to local projects, unattributed-resource view
- Audit log integration for token rotation + markup edits

**Output:** Admin can manage multiple businesses, each with their own DO account, and see all DO projects assigned to clients.

### Phase 2 — Cost ingestion (SMTP2GO first; DO billing parked)

Scope was refocused after Phase 1: DigitalOcean billing ingestion is **parked** while Laravel Cloud is evaluated as the hosting platform. Phase 2 builds the provider-agnostic cost-ingestion spine and lands **SMTP2GO** as the first billing provider, so the abstraction is proven by a second, very different provider (flat fee, no cost API, account-per-client).

- Schema: `provider_billing_payloads`, `cost_line_items`, `fx_rates` tables; alter `cost_providers` to add `config` (JSON) + `client_id` (nullable FK)
- Enums: `CostProviderSlug::Smtp2go`, `CostCategory`, `FxRateSource`, `CostAttributionState`
- Models + factories: `ProviderBillingPayload`, `CostLineItem`, `FxRate`
- Contracts split (`ResourceSyncAdapter` / `BillingSyncAdapter` / `CredentialValidator`) + `ProviderAdapterRegistry`
- `Smtp2go\Client`, `Smtp2go\BillingSync`, `Smtp2go\Dto\Smtp2goCycle`
- `CostAttributor`, `FxRateService` (Bank of Canada Valet API)
- Jobs: `SyncProviderBillingJob`, `AttributeCostsJob`, `FetchFxRateJob`, `ReconciliationAlertJob`; rename `SyncDigitalOceanProjectsJob` → `SyncProviderResourcesJob` (registry-routed) and **comment out its schedule entry** while DO is parked
- `Mail\Billing\ReconciliationAlert` (operator-facing)
- Admin UI: SMTP2GO fields on the cost-provider form; `/admin/billing/reconciliation` page; replace the placeholder card on `/admin/billing` with live cost tiles wired through `CurrentBusiness` + `ReconciliationReporter`
- Tests: `CheckpointD0SchemaTest`, `D1Smtp2goTest`, `D2FxTest`, `D3AttributionTest`, `D4ReconciliationTest`, `D5RegistryTest`, `D6JobsTest`, `D7AdminUiTest`

**SMTP2GO attribution:** one `cost_providers` row per SMTP2GO account, `client_id` set to that client. `Smtp2go\BillingSync` creates one synthetic `provider_resources` row (`resource_type = 'subscription'`) and auto-seeds its `resource_assignments` row to the client's sole active project. If the client has 0 or >1 active projects the resource stays unattributed and is surfaced in the existing unattributed-resources view for a one-time manual assignment. All attribution continues to flow through `resource_assignments` — no parallel path. The monthly fee is USD (`config.fee_currency = 'USD'`), so FX is a no-op for SMTP2GO today but built generically for CAD-billed clients downstream.

**Output:** Dashboard shows attributed SMTP2GO costs per client/project, surfaces unattributed accounts, no invoices yet. DO / Laravel Cloud billing drops in later as another `BillingSyncAdapter`.

### Phase 3 — Invoice engine + payments *(built)*

- `invoices`, `invoice_lines`, `recurring_line_templates`, `payments` tables
- `InvoiceBuilder`, `InvoiceApprover`, `InvoicePdfRenderer`, `PaymentRecorder`, `InvoiceNumberAllocator`
- `GenerateMonthlyDraftsJob`, `DraftReminderDigestJob`
- All four mailables (`InvoiceReadyForReview`, `InvoiceGenerationNeedsAttention`, `DraftReminderDigest`, `ClientInvoiceMail`)
- Per-business invoice templates (`templates/invoice.html` ported to `admin-v2.billing.invoices.templates.default`) with brand colours
- Per-business client email templates sharing the same branding
- Multi-currency: invoice issued in the client's `billing_currency`; hosting costs converted from the USD basis, and **individual recurring or manual lines may be priced in another currency** and converted at the period's rate, keeping the source amount and rate for display
- Late-fee terms and cheque payee snapshotted onto the invoice and rendered
- Hosted invoice view with payment banner
- Admin UI: invoice list/detail, edit manual lines, approve, send, record payment

**Output:** End-to-end automated drafts → operator notifications → review → approve → send → track payments.

Decisions taken during the build that departed from the original sketch:

- **PDF rendering is Browsershot**, not a PHP renderer. `templates/invoice.html` is a print-optimised design using flexbox and grid; dompdf would have meant rewriting it as tables. Hosts therefore need Node plus the Puppeteer Chromium download, or `LARAVEL_PDF_CHROME_PATH` pointed at a system Chrome.
- **The hosted view is an unguessable token URL, not a Laravel signed URL.** A signed URL expires, which would break the link in an email the client keeps — and the durable, revocable thing is the token, which the schema already carried.
- **The hosted page and the PDF are one render.** `InvoicePdfRenderer::html()` takes optional extra data; the hosted page passes a payment banner and download link, the PDF passes none. Two templates would have been free to drift.
- **Markup is two components.** `markup_value` is always the percent and `markup_fee` always the flat fee, because `hybrid` needs both and Phase 1 gave markup a single column.
- **Payment status is derived, never chosen.** `InvoiceStatus::allowedTransitions()` covers only operator moves; `isPaymentTracked()` governs payment-derived movement, so nothing can be marked paid without a payment record.

Still open from this phase: **fonts are fetched from Google Fonts at render time**, matching the original template, so a PDF generated offline falls back to system fonts. Self-hosting Archivo and Space Mono would make invoices render identically indefinitely.

### Phase 4 — Polish

- `RestatementDetector` + `DetectBillingRestatementsJob`
- Trailing-12mo threshold tracking with configurable warning percentage
- Overpayment-to-credit logic in `InvoiceBuilder`
- Recurring line template UI
- Placeholder hooks for future Stripe / Interac payment integration

## Deferred to later phases

- **DigitalOcean billing ingestion** — `DigitalOcean\BillingSync`, invoice DTOs, `Client` billing methods (`/v2/customers/my/billing_history`, `/v2/customers/my/invoices/{uuid}`), DO billing fixtures. Parked pending the hosting-platform decision. The Phase 1 DO *resource* sync stays in the codebase but its schedule entry is disabled.
- **Laravel Cloud as a cost provider** — under consideration as the hosting platform. Usage-based pricing, so it fits the same `BillingSyncAdapter` shape DO would have used. Before committing: confirm whether Laravel Cloud exposes billing/usage programmatically (the Usage page exists in their dashboard; an API is referenced but the billing surface is unverified).
- **`RestatementDetector` / adjustment lines** — only relevant once a provider that restates prior periods is connected.
- **Stripe / Interac integration** — webhook auth model, refund handling, fee accounting
- **Client-facing portal** — vs. just signed URLs: would let clients see history, download past invoices, update contact info
- **Late fees / interest automation** — v1 only renders a free-text `late_fee_terms` snapshot in the invoice footer. Automated overdue tracking, automatic late-fee line generation, and reminder cadence are deferred. The footer text is the policy disclosure; future work will enforce it programmatically.
