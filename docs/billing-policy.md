# Billing Policy

This document captures the decisions that govern the automated billing system. The data model and code should reflect these rules; deviations require updating this doc first.

## Business entities

- The system supports **multiple businesses** (e.g. this site + TrackerPull). Each business has independent clients, projects, cost provider credentials, invoice templates, invoice numbering, and tax registration status.
- A client belongs to exactly one business. If the same human is a client of two businesses, they are two `client` records.
- Invoices store their business at issue time, so rebranding or restructuring a business later does not alter historical invoices.

## Attribution model

- The unit of cost attribution is the **DigitalOcean Project**, not the individual resource.
- A client may own one or more DO Projects. A DO Project belongs to exactly one client — projects are never shared.
- Resources sitting in DO's Default Project at sync time are flagged as **unattributed** and surfaced in a reconciliation alert.
- The system stores a daily snapshot of `(resource_id, project_uuid, observed_at)` to provide effective-dated history for audits and disputes.

## Cost ingestion

- Every cost provider is pulled on a schedule (daily where the provider has a usage/billing API).
- Raw provider API payloads are stored verbatim alongside derived records, keyed by `(cost_provider_id, period, content_hash)`.
- Where a provider exposes project / resource mappings (DigitalOcean), those are synced daily too.
- Account-level charges that cannot be attributed to a project (support plans, account credits, etc.) are bucketed as **overhead** and not passed to clients. They appear on an internal reconciliation report so the gap between "the provider billed me" and "I billed clients" is always visible.

### DigitalOcean — parked

DO billing ingestion is **not built in v1**. Laravel Cloud is under consideration as the hosting platform; building a DO billing adapter now would be wasted if the systems move. The DO *resource* sync from Phase 1 remains in the codebase but its scheduled run is disabled. When the hosting decision is made, the chosen provider (DO or Laravel Cloud — both usage-based) is added as a billing adapter behind the same interface SMTP2GO uses.

### SMTP2GO — per-client email accounts

- A **separate SMTP2GO account exists per client.** Each account is one cost provider record, linked 1:1 to a client.
- SMTP2GO exposes **no cost, invoice, or billing-amount API** — only usage (`/stats/email_cycle`: emails sent / remaining / plan allowance, cycle dates). Plans are flat monthly subscriptions.
- The per-account monthly fee is therefore **operator-configured** (`monthly_fee`, `fee_currency` — USD today) on the cost provider, not synced. It is entered **all-in** (whatever SMTP2GO actually charges, including any tax they add); while the business is unregistered that tax is a cost input absorbed by markup, consistent with the DO tax treatment above. `/stats/email_cycle` is still pulled and stored each period as usage evidence and to drive an over-quota warning.
- The fee is recognised once per calendar-month billing period at 1×. SMTP2GO's rolling cycle dates are stored for context only; they do not shift which period the charge lands in.
- Because the account maps to a client, the SMTP2GO cost **is client-attributable** (not overhead). It attributes to one of that client's projects and takes that project's markup like any other cost. A synthetic "subscription" resource represents the account and is assigned to a project through the normal effective-dated `resource_assignments` mechanism; it auto-assigns when the client has exactly one active project.

## Currency

- Providers charge in their own currency (DO: USD; SMTP2GO: USD). The canonical cost basis is **USD** — every `cost_line_item` carries the provider's `source_amount`/`source_currency` plus a normalized `usd_amount`. Client invoices are issued in the client's **billing currency**, configured per client and constrained to the set of currencies supported by the client's business.
- The system supports multiple invoice currencies (CAD primary, USD common). Each business declares its `default_currency` and `supported_currencies`.
- FX rate: **Bank of Canada monthly average** for the billing month is the primary source for any pair needed. Pulled per pair (USD → CAD, USD → USD no-op, etc.) on demand.
- The FX rate used (rate, source, period) is snapshotted onto the invoice so historical invoices remain reproducible even if a rate source is later replaced.

## Tax

Tax registration status is tracked **per business**. Each business has its own $30K threshold and its own `tax_registered_from` date. As of v1, no business is GST/HST registered.

While unregistered:

- Client invoices show **no tax line**. Subtotal equals total.
- DO's GST/HST charged on services to the business is **not recoverable** (no ITC available). It is treated as a cost input: `(DO USD cost + DO USD tax) → convert to CAD → apply markup`. The client sees one rolled-up hosting figure; DO's tax is invisibly absorbed by markup.
- The system tracks **trailing 12-month revenue** and surfaces a warning on the dashboard as revenue approaches $30K, with sufficient lead time to register before crossing the CRA's 4-consecutive-quarters threshold.

The schema anticipates the future transition:

- A `tax_registered_from` date lives on the business configuration.
- Invoice generation branches on whether the invoice date falls before or after that date.
- Past invoices remain immutable and correct regardless of future registration.

After registration (future state, not implemented in v1):

- DO's tax becomes recoverable via ITC and is removed from the cost basis.
- GST/HST is charged on the CAD invoice total at the client's province's rate (place-of-supply rules).

## Markup

- Markup is configured **per project**, not per client.
- A client-level default exists; projects inherit it unless they override it. A project overrides type *and* values together — there is no partial inheritance.
- Supported markup types: `percent`, `fixed_fee`, `hybrid` (fee + percent), `passthrough` (cost only).
- Markup is expressed as two components: `markup_value` is always the **percent** and `markup_fee` is always the **flat fee**. A type uses one, both, or neither, and the form only shows the components its type uses.
- Markup applies to the **cost basis in the client's billing currency** — provider costs are converted first, then marked up, so markup is charged on what the work cost in the currency the client is billed in. The basis already includes the provider's tax while the business is unregistered.
- Markup applies to **hosting lines only**. Recurring and manual lines are entered at the price charged, so applying markup to them would double-count the operator's own pricing decision.

## Invoice composition

A draft invoice is composed of:

- **DO-derived hosting lines**: a parent line per project (e.g. "Hosting — Acme Project") with sub-items for transparency (Droplet, Database, Backups, etc.).
- **Recurring per-client / per-project items**: configurable line items that auto-attach to drafts (e.g. Laravel Forge, domain renewals, retainers).
- **Manual additions**: ad-hoc line items, discounts, and credits added at review time.

Rules:

- Provider-derived numbers are **not editable**. Corrections happen via separate adjustment lines so the audit trail is preserved.
- Sub-items are display-only; markup applies to the parent total, not per sub-item.
- Any line may carry a free-text **description**, rendered under its title. A four-figure line needs to explain itself before a client can approve it for payment.
- **A line may be priced in a currency the client is not billed in** — a domain renewal bought in USD on a CAD invoice. Recurring templates and manual lines both convert at the invoice period's rate, and the line keeps the original amount, its currency, and the rate applied, so the document shows its working ("USD 18.00 at 1.375") rather than asserting a converted figure. A rate that cannot be resolved refuses the line rather than guessing.
- The invoice carries: client, billing period, FX rate used, line items, subtotal, total, status, timestamps.

## Approval workflow

- All invoices are generated in `draft` status.
- The user reviews each draft, optionally edits (manual lines, discounts, credits), and marks `approved`.
- Approval triggers send: a **branded** email to the client containing the PDF as an attachment *and* a signed URL to a hosted view of the invoice.
- Client-facing emails use a per-business Blade Mailable template, so each business presents its own header, logo, colors, and footer. PDF templates are also per-business.
- The hosted view shows the same content as the PDF and is the future home for online payment status.
- No invoice leaves draft without explicit approval.

## Late fee terms

- Each business configures a free-text `late_fee_terms` field (e.g. *"A 2% monthly interest charge applies to balances unpaid after 30 days."*).
- The terms are **snapshotted onto each invoice at generation time** and rendered in the invoice footer (PDF + hosted view).
- v1 does not automate overdue detection, late-fee line generation, or escalation cadence. The footer text is the policy disclosure to the client; programmatic enforcement is a later phase.

## Payments

- Payments are tracked as first-class records against an invoice. v1 supports manually recording payments (e-transfer, cheque, cash, other). Stripe / Interac integrations are deferred to a later phase but the schema anticipates them via a `method` field and a generic `reference` field.
- Multiple payments per invoice are supported (partial payments).
- Invoice status is derived from payment sum:
  - `paid` when total payments ≥ invoice total
  - `partially_paid` when 0 < total payments < invoice total
  - otherwise stays at `approved` / `sent`
- The hosted invoice view surfaces payment status:
  - Partial: *"Partial payment of $X.XX received on [date]. Balance due: $Y.YY."*
  - Paid: *"Paid in full on [date of final payment]."*
- Overpayments are permitted and surface as a credit on the next draft invoice for the same client.
- Payment edits / voids are written to the audit log.

## Restatements and adjustments

- DigitalOcean occasionally restates prior periods (late credits, corrections, disputes).
- When the system detects a diff between a stored payload and a fresh pull for the same period, the delta becomes an **adjustment line on the next draft invoice** for the affected client.
- Past invoices are immutable. They are never re-issued.

## Security

- Provider API tokens/keys are **read-only** where the provider supports scoping (DO token; SMTP2GO key limited to stats endpoints), stored encrypted in the database (not in `.env`), rotated quarterly.
- Token access and rotation are logged.
- Only the owner-user has access to the billing UI; no client-facing surfaces touch provider data.

## Client lifecycle

- A departing client's resources are spun down, not reassigned to other clients.
- A final invoice is generated covering DO costs accrued through the termination date, prorated to actual usage as reported by DO.
- Historical mappings and invoices are retained indefinitely so old invoices remain reproducible.

## Operator notifications

- When a draft invoice is auto-generated, the operator receives an email *"Invoice ready for review"* containing a link to the draft.
- When a scheduled invoice generation **fails or is skipped** (e.g. cost ingestion incomplete, no DO data yet), the operator receives an email *"Invoice generation needs attention"*.
- A draft that has not been approved continues to generate a daily **reminder email** until it is approved or voided. Reminders include the draft age and a direct link.
- Notification preferences (recipient address, daily reminder time) are configured per business.

## Reconciliation reporting

The dashboard surfaces at minimum:

- **Unattributed resources**: anything in a provider's default/holding project, in a project not mapped to a client, or a synthetic account resource (e.g. an SMTP2GO account) not yet assigned to a project.
- **Cost gap**: provider-reported total billed this period vs. sum of client-attributed costs + overhead. Anything non-zero needs an explanation. Only meaningful once a provider with a cost API is connected — with SMTP2GO alone the "billed" figure is the operator-entered fee, so the gap is zero by construction and the tile shows `—`.
- **Trailing 12-month revenue per business**: progress toward each business's $30K registration threshold. *(Phase 3 — needs invoices.)*
- **Pending drafts**: invoices awaiting approval, oldest first. *(Phase 3.)*

## Provider abstraction

The data model treats every provider as one **cost provider** among many. Cost providers are scoped to a business — each business has its own credentials per provider. A provider implements only the capabilities it has: resource sync, billing sync, credential validation. Adding Forge, a registrar, Backblaze, Laravel Cloud, etc. later requires a new adapter but no schema migration. Provider identifiers, raw payloads, and currency are stored generically.

Two provider shapes are supported:

- **Account-per-business** (DigitalOcean, Laravel Cloud) — `cost_providers.client_id` is null; costs attribute to projects via synced resources and their assignments; unattributable account charges are overhead.
- **Account-per-client** (SMTP2GO) — `cost_providers.client_id` is set; a single synthetic resource represents the account and attributes the whole charge to the client's project.

v1 implements **SMTP2GO** only. DigitalOcean resource sync exists from Phase 1 (scheduled run disabled); DigitalOcean/Laravel Cloud billing is deferred — see *Cost ingestion*.
