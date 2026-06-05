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

- DO billing data is pulled daily.
- Raw DO API payloads are stored verbatim alongside derived records, keyed by `(invoice_uuid, fetched_at)`.
- Project / resource mappings are also synced daily.
- Account-level charges that cannot be attributed to a project (support plans, account credits, etc.) are bucketed as **overhead** and not passed to clients. They appear on an internal reconciliation report so the gap between "DO billed me" and "I billed clients" is always visible.

## Currency

- DO charges in USD. Client invoices are issued in the client's **billing currency**, configured per client and constrained to the set of currencies supported by the client's business.
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
- A client-level default exists; new projects inherit it but can be overridden.
- Supported markup types: `percent`, `fixed_fee`, `hybrid` (fee + percent), `passthrough` (cost only).
- Markup is applied to the CAD cost basis (which already includes DO's tax while unregistered).

## Invoice composition

A draft invoice is composed of:

- **DO-derived hosting lines**: a parent line per project (e.g. "Hosting — Acme Project") with sub-items for transparency (Droplet, Database, Backups, etc.).
- **Recurring per-client / per-project items**: configurable line items that auto-attach to drafts (e.g. Laravel Forge, domain renewals, retainers).
- **Manual additions**: ad-hoc line items, discounts, and credits added at review time.

Rules:

- DO-derived numbers are **not editable**. Corrections happen via separate adjustment lines so the audit trail is preserved.
- Sub-items are display-only; markup applies to the parent total, not per sub-item.
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

- DO API token is **read-only**, stored encrypted in the database (not in `.env`), rotated quarterly.
- Token access is logged.
- Only the owner-user has access to the billing UI; no client-facing surfaces touch DO data.

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

- **Unattributed resources**: anything in the DO Default Project, or in a project not mapped to a client.
- **Cost gap**: DO total billed this period vs. sum of client-attributed costs. Anything non-zero needs an explanation.
- **Trailing 12-month revenue per business**: progress toward each business's $30K registration threshold.
- **Pending drafts**: invoices awaiting approval, oldest first.

## Provider abstraction

While v1 only implements DigitalOcean, the data model treats DO as one **cost provider** among many. Cost providers are scoped to a business — each business has its own credentials per provider. Adding Forge, a registrar, Backblaze, etc. later should require new ingestion adapters but no schema migration. Provider identifiers, raw payloads, and currency are stored generically.
