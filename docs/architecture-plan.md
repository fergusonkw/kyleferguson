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

- **`provider_billing_payloads`** — cost_provider_id, period (YYYY-MM), raw_payload (JSON), content_hash, fetched_at
- **`cost_line_items`** — cost_provider_id, provider_resource_id (nullable for unattributable), project_id (nullable), period, usd_amount, usd_tax, category, source_payload_id, derived_at

### FX

- **`fx_rates`** — currency_from, currency_to, period, rate, source (`bank_of_canada`, future others), fetched_at — unique on (from, to, period, source). Supports arbitrary currency pairs so a USD-billed client and a CAD-billed client are both handled from the same DO USD cost basis.

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

- **`DigitalOcean\Client`** — HTTP wrapper. Read-only token. Retry with backoff. Rate-limit aware.
- **`DigitalOcean\ProjectSync`** — pulls projects + resources, writes `provider_resources`, closes/opens `resource_assignments` on changes.
- **`DigitalOcean\BillingSync`** — pulls billing history + invoice CSVs, stores raw payload, content-hash diff against prior payload, writes `cost_line_items`.

### Cross-provider

- **`Contracts\CostProviderAdapter`** — interface every provider implements (`syncResources()`, `syncBilling()`, `validateCredentials()`).
- **`CostAttributor`** — maps `cost_line_items` → projects via current `resource_assignments`. Flags unattributed for reconciliation.
- **`FxRateService`** — fetches monthly-average rates for any currency pair needed (Bank of Canada as primary source; pair selection driven by client billing currencies present in the period). Caches in `fx_rates`.

### Invoicing

- **`InvoiceBuilder`** — for a given (business, client, period): determines the issue currency (from client.billing_currency) → looks up FX rate (USD cost basis → issue currency) → aggregates attributed costs by project → applies project's markup → adds recurring line templates active during the period → renders the late-fee terms snapshot for the footer → creates `invoice` (draft) + `invoice_lines`. Idempotent on (business, client, period).
- **`InvoiceApprover`** — handles status transitions (`draft → approved → sent`). Triggers PDF generation + email send. Refuses illegal transitions.
- **`InvoicePdfRenderer`** — picks `template_view_snapshot` (or business default at issue time), renders to PDF with business branding (logo, colors), includes the late-fee terms snapshot in the footer, writes to storage, returns path.
- **`PaymentRecorder`** — records/edits/voids payments. Recomputes invoice status. Writes to `AuditLog`.
- **`InvoiceNumberAllocator`** — atomic per-business sequence with prefix.

### Reporting & notifications

- **`ReconciliationReporter`** — computes dashboard tiles: unattributed resources, cost gap, trailing-12mo per business, pending drafts.
- **`OperatorNotifier`** — emits the `InvoiceReadyForReview`, `InvoiceGenerationNeedsAttention`, and `DraftReminderDigest` mailables.
- **`RestatementDetector`** — diffs fresh DO payloads vs. stored; queues adjustment lines onto the next open draft for affected clients.

## Jobs & scheduling

All jobs implement `ShouldQueue` and are idempotent. Scheduled in `routes/console.php`.

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SyncDigitalOceanProjectsJob` | Daily 02:00 | Refresh project/resource mapping |
| `SyncDigitalOceanBillingJob` | Daily 03:00 | Pull billing data, store payloads, derive line items |
| `DetectBillingRestatementsJob` | Daily 03:30 | Diff payloads, queue adjustments |
| `FetchFxRateJob` | 1st of month 01:00 | Bank of Canada monthly average |
| `GenerateMonthlyDraftsJob` | 1st of month 06:00 | After FX + sync complete, build drafts per business → triggers "ready for review" email |
| `DraftReminderDigestJob` | Daily at each business's `daily_reminder_time` | Email digest of unapproved drafts older than 1 day |
| `ReconciliationAlertJob` | Daily 08:00 | Alert if unattributed resources or non-zero cost gap |

Failure handling: each scheduled job that produces a needed artifact has a sibling that detects "did the expected output appear" and sends `InvoiceGenerationNeedsAttention` if not.

## Admin UI

All views under `resources/views/admin-v2/billing/`. Extend `admin-v2.layouts.vertical`. Use existing `x-admin-v2.*` components throughout (`card`, `datatable`, `form.*`, `modal`, `offcanvas`, `button`, `alert`, `status-badge`, `stat-card`, `page-title`).

| Route | Purpose |
|-------|---------|
| `/admin/billing` | Dashboard: tiles (per business), pending drafts list, trailing-12mo gauges |
| `/admin/billing/businesses` | CRUD businesses + per-business config |
| `/admin/billing/clients` | CRUD clients (scoped by business switcher) |
| `/admin/billing/projects` | CRUD projects, link to DO project, markup config |
| `/admin/billing/providers` | Manage DO tokens, view sync status, trigger manual sync |
| `/admin/billing/invoices` | List with filters (business, client, status) |
| `/admin/billing/invoices/{invoice}` | Detail: lines, edit manual/discount/credit/adjustment lines, approve, send, record payment |
| `/admin/billing/reconciliation` | Unattributed resources, cost gap drilldown, restatement history |
| `/invoices/{token}` (public) | Hosted invoice view (signed URL), payment banner |

## Mailables

- `InvoiceReadyForReview` — to operator when draft generated
- `InvoiceGenerationNeedsAttention` — to operator on failure / missing inputs
- `DraftReminderDigest` — to operator daily, lists unapproved drafts
- `ClientInvoiceMail` — to client on approval; PDF attached + signed URL to hosted view. **Branded per business**: uses `email_template_view_snapshot` (a Blade Mailable template) so each business has its own header/logo/colors/footer. Default template lives at `resources/views/emails/invoices/default.blade.php`; per-business templates can override.

## Testing strategy

PHPUnit feature tests (per project rules). Most logic exercised via service-level feature tests; HTTP layer tested where it adds value (form validation, signed URL access).

Required coverage:

- `ProjectSync`: happy path, unchanged-sync no-op, resource moved between projects (closes/opens assignment row)
- `BillingSync`: happy path, restatement diff produces adjustment line on next draft
- `CostAttributor`: attributed, default-project (unattributed), unmapped resource cases
- `InvoiceBuilder`: each markup type (percent, fixed, hybrid, passthrough); with/without recurring items; idempotency on re-run
- `InvoiceApprover`: legal and illegal status transitions
- `InvoicePdfRenderer`: picks correct per-business template
- `PaymentRecorder`: partial → partial → paid progression; overpayment surfaces as next-draft credit; void recomputes status
- `FxRateService`: mocked Bank of Canada response, caching
- Tax: invoice generation while unregistered (no tax line); pre-set `tax_registered_from` to test the registered branch even before it's used in production
- Multi-currency: a USD-billed client and a CAD-billed client in the same period both produce correct invoices from the shared USD cost basis
- Email branding: `ClientInvoiceMail` renders the correct per-business template (header, logo, colors, footer)
- Hosted view: valid signed URL renders, expired/invalid token denied
- Notifications: ready-for-review fires on draft generation; daily reminder digest fires only for unapproved drafts > 1 day old
- Trailing-12mo threshold warning fires at configurable percentage

Fixtures: DO API responses in `tests/Fixtures/digitalocean/`.

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

### Phase 2 — Cost ingestion

- `provider_billing_payloads`, `cost_line_items`, `resource_assignments`, `fx_rates` tables
- `DigitalOcean\BillingSync`, `CostAttributor`, `FxRateService`
- `SyncDigitalOceanBillingJob`, `FetchFxRateJob`, `ReconciliationAlertJob`
- Dashboard reconciliation tiles (per business)

**Output:** Dashboard shows attributed costs per client/project, surfaces gaps, no invoices yet.

### Phase 3 — Invoice engine + payments

- `invoices`, `invoice_lines`, `recurring_line_templates`, `payments` tables
- `InvoiceBuilder`, `InvoiceApprover`, `InvoicePdfRenderer`, `PaymentRecorder`, `InvoiceNumberAllocator`
- `GenerateMonthlyDraftsJob`, `DraftReminderDigestJob`
- All four mailables (`InvoiceReadyForReview`, `InvoiceGenerationNeedsAttention`, `DraftReminderDigest`, `ClientInvoiceMail`)
- Per-business invoice templates (existing `templates/invoice.html` becomes the default) with logo and brand colors
- Per-business client email templates (Blade Mailables) sharing the same branding
- Multi-currency support: invoice issued in client's `billing_currency`; FX from USD cost basis pulled per pair as needed
- Late-fee terms rendered in invoice footer (free-text field on business, snapshotted onto invoice at generation)
- Hosted invoice view (signed URL) with payment banner
- Admin UI: invoice list/detail, edit manual lines, approve, send, record payment

**Output:** End-to-end automated drafts → operator notifications → review → approve → send → track payments.

### Phase 4 — Polish

- `RestatementDetector` + `DetectBillingRestatementsJob`
- Trailing-12mo threshold tracking with configurable warning percentage
- Overpayment-to-credit logic in `InvoiceBuilder`
- Recurring line template UI
- Placeholder hooks for future Stripe / Interac payment integration

## Deferred to later phases

- **Stripe / Interac integration** — webhook auth model, refund handling, fee accounting
- **Client-facing portal** — vs. just signed URLs: would let clients see history, download past invoices, update contact info
- **Late fees / interest automation** — v1 only renders a free-text `late_fee_terms` snapshot in the invoice footer. Automated overdue tracking, automatic late-fee line generation, and reminder cadence are deferred. The footer text is the policy disclosure; future work will enforce it programmatically.
