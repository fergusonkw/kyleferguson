{{--
    Default invoice template.

    Ported from templates/invoice.html — same brand (charcoal field + red
    accent, Archivo + Space Mono), print-optimised for Letter.

    Everything rendered here comes from the invoice's own snapshots, never from
    the live business or client, so an invoice issued last year still renders as
    it was issued even after a rebrand or a change of address.

    A business can point `invoice_template_view` at its own copy of this file;
    the name in use is snapshotted onto the invoice at generation time.
--}}
@php
    // Only the hosted client page supplies these; the PDF renders without
    // them, so the printed document stays the canonical record.
    $banner ??= null;
    $downloadUrl ??= null;
    $hosted = $banner !== null || $downloadUrl !== null;

    $business = $invoice->business_snapshot ?? [];
    $client = $invoice->client_snapshot ?? [];
    $currency = $invoice->issue_currency;

    $money = fn ($value): string => ($value < 0 ? '−$' : '$').number_format(abs((float) $value), 2);

    $accent = $business['brand_primary_color'] ?? '#c0392b';
    $charcoal = $business['brand_secondary_color'] ?? '#15161a';

    // Snapshots are JSON captured at generation time, so an older invoice may
    // predate a field. Every read here tolerates a missing key.
    $issuerName = $business['legal_name'] ?? $business['name'] ?? '';
    $monogram = collect(preg_split('/\s+/', (string) ($business['name'] ?? '')))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $amountPaid = $invoice->amountPaid();
    $hasPayments = bccomp($amountPaid, '0.00', 2) === 1;

    // Built here rather than inline so optional lines can be dropped without
    // leaving blank rows in the address block.
    $issuerLines = array_values(array_filter([
        $business['contact_email'] ?? null,
        $business['address'] ?? null,
    ]));
    $billToLines = array_values(array_filter([
        $client['contact_name'] ?? null,
        $client['billing_address'] ?? null,
        $client['contact_email'] ?? null,
    ]));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
@if($hosted)
  <meta name="robots" content="noindex, nofollow">
@endif
<title>{{ $invoice->invoice_number }} — {{ $business['name'] ?? 'Invoice' }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root {
    --charcoal:  {{ $charcoal }};
    --ink:       #1a1c21;
    --body:      #3d3d3d;
    --mute:      #6a6d75;
    --line:      #e2e3e6;
    --panel:     #f6f6f7;
    --accent:    {{ $accent }};
    --accent-soft:#f8efed;
    --white:     #ffffff;
    --font-body: 'Archivo', system-ui, -apple-system, 'Segoe UI', sans-serif;
    --font-mono: 'Space Mono', ui-monospace, 'Cascadia Mono', monospace;
    --page-width: 760px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: var(--font-body);
    font-size: 10.5pt;
    line-height: 1.6;
    color: var(--body);
    background: #d8d8d8;
    letter-spacing: 0.01em;
    -webkit-font-smoothing: antialiased;
  }
  .page { background: var(--white); max-width: var(--page-width); margin: 32px auto; }

  .masthead {
    background: var(--charcoal);
    padding: 40px 48px 34px;
    display: flex; justify-content: space-between; align-items: flex-start; gap: 32px;
  }
  .issuer { display: flex; gap: 14px; align-items: flex-start; }
  .monogram {
    position: relative; width: 36px; height: 36px; border: 1px solid #383c46;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
  .monogram::before {
    content: ''; position: absolute; top: -1px; left: -1px; width: 8px; height: 8px;
    border-top: 1px solid var(--accent); border-left: 1px solid var(--accent);
  }
  .monogram span { font-weight: 700; font-size: 13px; letter-spacing: -0.02em; color: #e9e9ec; }
  .issuer .name { font-size: 14pt; font-weight: 700; color: #f3f3f5; letter-spacing: -0.01em; line-height: 1.1; }
  .issuer .contact {
    font-family: var(--font-mono); font-size: 7.8pt; color: #b8bac1;
    margin-top: 10px; line-height: 1.7; letter-spacing: 0.02em; white-space: pre-line;
  }
  .doc-id { text-align: right; flex-shrink: 0; }
  .doc-id .doc-word { font-size: 21pt; font-weight: 700; color: #f3f3f5; letter-spacing: 0.02em; text-transform: uppercase; line-height: 1; }
  .doc-id .doc-no { font-family: var(--font-mono); font-size: 9pt; color: var(--accent); margin-top: 8px; letter-spacing: 0.06em; }
  .title-rule { height: 3px; background: linear-gradient(to right, var(--accent), transparent); }

  .meta { display: flex; justify-content: space-between; gap: 32px; padding: 28px 48px 6px; }
  .meta .block { flex: 1; }
  .meta .field-label {
    font-family: var(--font-mono); font-size: 7.5pt; letter-spacing: 0.16em;
    text-transform: uppercase; color: var(--accent); margin-bottom: 7px;
  }
  .bill-to .party-name { font-size: 11pt; font-weight: 700; color: var(--ink); }
  .bill-to address { font-style: normal; font-size: 9.4pt; color: var(--body); line-height: 1.55; margin-top: 3px; white-space: pre-line; }
  .meta-dates { max-width: 270px; }
  .meta-dates table { width: 100%; border-collapse: collapse; font-size: 9.2pt; }
  .meta-dates td { padding: 3px 0; vertical-align: top; }
  .meta-dates td.k {
    font-family: var(--font-mono); font-size: 7.8pt; letter-spacing: 0.04em;
    text-transform: uppercase; color: var(--mute); padding-right: 16px; white-space: nowrap;
  }
  .meta-dates td.v { text-align: right; color: var(--ink); font-weight: 600; }

  .content { padding: 24px 48px 48px; }

  table.line-items { width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 9.4pt; }
  table.line-items thead tr { background: var(--charcoal); color: #f3f3f5; }
  table.line-items thead th {
    font-family: var(--font-mono); font-size: 7.6pt; letter-spacing: 0.1em;
    text-transform: uppercase; text-align: left; padding: 9px 12px; font-weight: 400;
  }
  table.line-items tbody td { padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.line-items tbody tr:nth-child(even) { background: var(--panel); }
  table.line-items th.right, table.line-items td.right { text-align: right; }
  table.line-items td .item-title { font-weight: 700; color: var(--ink); }
  table.line-items td .item-desc { font-size: 8.8pt; color: var(--mute); margin-top: 2px; }
  table.line-items td .item-desc span { display: inline-block; margin-right: 14px; }
  /* Free-text explanation of a line — wraps and keeps its line breaks. */
  table.line-items td .item-note {
    font-size: 8.8pt; color: var(--body); margin-top: 3px;
    line-height: 1.5; white-space: pre-line; max-width: 46em;
  }

  .totals-wrap { display: flex; justify-content: flex-end; margin-top: 18px; }
  table.totals { width: 300px; border-collapse: collapse; font-size: 9.6pt; }
  table.totals td { padding: 6px 12px; }
  table.totals td.k { color: var(--mute); }
  table.totals td.v { text-align: right; color: var(--ink); font-weight: 600; }
  table.totals tr.sub td { border-bottom: 1px solid var(--line); }
  table.totals tr.grand td { background: var(--charcoal); padding: 10px 12px; }
  table.totals tr.grand td.k { color: #cfd1d6; font-weight: 600; }
  table.totals tr.grand td.v { color: #fff; font-weight: 700; }

  .well {
    background: var(--panel); border-left: 3px solid var(--line);
    padding: 14px 18px; font-size: 9.2pt; margin-top: 18px;
  }
  .well > :last-child { margin-bottom: 0; }
  .well h3 {
    font-family: var(--font-mono); font-size: 7.6pt; letter-spacing: 0.14em;
    text-transform: uppercase; color: var(--mute); margin-bottom: 8px; font-weight: 400;
  }
  .well p { margin-bottom: 8px; }
  .well ul { margin: 8px 0 0 18px; }
  .well li { margin-bottom: 6px; }
  .well--accent { border-left-color: var(--accent); background: var(--accent-soft); }
  .well--accent h3 { color: var(--accent); }
  .well-row { display: flex; gap: 18px; }
  .well-row .well { flex: 1; }

  .fine-print { font-size: 8.4pt; color: var(--mute); margin-top: 14px; }

  .doc-footer {
    display: flex; justify-content: space-between;
    padding: 14px 48px; background: var(--charcoal);
    font-family: var(--font-mono); font-size: 7.6pt; color: #8b8e97; letter-spacing: 0.04em;
  }
  .doc-footer .footer-name { color: #cfd1d6; }

  .status-stamp {
    display: inline-block; margin-top: 10px; padding: 3px 10px;
    font-family: var(--font-mono); font-size: 7.6pt; letter-spacing: 0.12em;
    text-transform: uppercase; border: 1px solid var(--accent); color: var(--accent);
  }

  /* Hosted client page only — never printed. */
  .client-bar {
    max-width: var(--page-width); margin: 32px auto -16px;
    display: flex; flex-wrap: wrap; gap: 12px;
    align-items: center; justify-content: space-between;
  }
  .client-bar .status {
    flex: 1 1 320px; padding: 12px 16px; font-size: 10pt; line-height: 1.5;
    border-left: 3px solid;
  }
  .client-bar .status.paid { background: #e8f5ed; border-color: #1e7c47; color: #14532d; }
  .client-bar .status.partial { background: #fdf4e6; border-color: #b26b00; color: #7a4a00; }
  .client-bar .status.overdue { background: var(--accent-soft); border-color: var(--accent); color: #7f231a; }
  .client-bar .download {
    display: inline-block; padding: 11px 20px; background: var(--charcoal); color: #fff;
    text-decoration: none; font-size: 10pt; font-weight: 600;
  }

  @media print {
    body { background: none; }
    .client-bar { display: none; }
    @page { size: Letter; margin: 14mm; }
    @page :first { margin-top: 0; }
    .page { margin: 0; max-width: none; box-shadow: none; }
    .masthead { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.line-items { page-break-inside: auto; font-size: 8.8pt; }
    table.line-items thead { display: table-header-group; }
    .totals-wrap, .well, blockquote { page-break-inside: avoid; }
  }
</style>
</head>
<body>

@if($hosted)
  <div class="client-bar">
    @if($banner !== null)
      <div class="status {{ $banner['tone'] }}">{{ $banner['message'] }}</div>
    @else
      <div style="flex: 1 1 320px;"></div>
    @endif
    @if($downloadUrl !== null)
      <a class="download" href="{{ $downloadUrl }}">Download PDF</a>
    @endif
  </div>
@endif

<div class="page">

  <div class="masthead">
    <div class="issuer">
      <div class="monogram"><span>{{ $monogram }}</span></div>
      <div>
        <div class="name">{{ $issuerName }}</div>
        <div class="contact">{{ implode("\n", $issuerLines) }}</div>
      </div>
    </div>
    <div class="doc-id">
      <div class="doc-word">Invoice</div>
      <div class="doc-no">{{ $invoice->invoice_number }}</div>
      @if($invoice->status === \App\Enums\Billing\InvoiceStatus::Void)
        <div class="status-stamp">Void</div>
      @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Paid)
        <div class="status-stamp">Paid</div>
      @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Draft)
        <div class="status-stamp">Draft</div>
      @endif
    </div>
  </div>
  <div class="title-rule"></div>

  <div class="meta">
    <div class="block bill-to">
      <div class="field-label">Bill To</div>
      <div class="party-name">{{ $client['name'] ?? '' }}</div>
      <address>{{ implode("\n", $billToLines) }}</address>
    </div>
    <div class="block meta-dates">
      <table>
        <tr>
          <td class="k">Invoice #</td>
          <td class="v">{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
          <td class="k">Issue Date</td>
          <td class="v">{{ $invoice->issued_on?->format('F j, Y') ?? '—' }}</td>
        </tr>
        <tr>
          <td class="k">Due Date</td>
          <td class="v">{{ $invoice->due_on?->format('F j, Y') ?? '—' }}</td>
        </tr>
        <tr>
          <td class="k">Billing Period</td>
          <td class="v">{{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}</td>
        </tr>
      </table>
    </div>
  </div>

  <div class="content">

    <table class="line-items">
      <thead>
        <tr>
          <th style="width:74%">Description</th>
          <th class="right" style="width:26%">Amount</th>
        </tr>
      </thead>
      <tbody>
        @forelse($invoice->topLevelLines as $line)
          <tr>
            <td>
              <div class="item-title">{{ $line->label }}</div>
              @if(filled($line->description))
                <div class="item-note">{{ $line->description }}</div>
              @endif
              @if($line->children->isNotEmpty())
                <div class="item-desc">
                  @foreach($line->children as $child)
                    <span>{{ $child->label }} {{ $money($child->amount) }}</span>
                  @endforeach
                </div>
              @endif
              @if($line->conversionNote())
                <div class="item-desc"><span>{{ $line->conversionNote() }}</span></div>
              @endif
            </td>
            <td class="right">{{ $money($line->amount) }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="2" style="text-align:center;color:var(--mute);">No billable items for this period.</td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="totals-wrap">
      <table class="totals">
        <tr class="sub">
          <td class="k">Subtotal</td>
          <td class="v">{{ $money($invoice->subtotal) }}</td>
        </tr>
        @if(bccomp($invoice->tax_total, '0.00', 2) === 1)
          <tr class="sub">
            <td class="k">Tax</td>
            <td class="v">{{ $money($invoice->tax_total) }}</td>
          </tr>
        @endif
        @if($hasPayments)
          <tr class="sub">
            <td class="k">Paid</td>
            <td class="v">−{{ $money($amountPaid) }}</td>
          </tr>
        @endif
        <tr class="grand">
          <td class="k">{{ $hasPayments ? 'Balance Due' : 'Amount Due' }}</td>
          <td class="v">{{ $money($hasPayments ? $invoice->balanceDue() : $invoice->total) }} {{ $currency }}</td>
        </tr>
      </table>
    </div>

    <div class="well-row" style="margin-top:24px;">
      <div class="well">
        <h3>Payment Details</h3>
        <p style="margin:0 0 6px;"><strong>Interac e-Transfer:</strong> {{ $business['contact_email'] ?? '' }}</p>
        @if(!empty($business['cheque_payable_to']))
          <p style="margin:0 0 6px;"><strong>Cheque payable to:</strong> {{ $business['cheque_payable_to'] }}</p>
        @endif
        <p style="margin:0;"><em>Please reference {{ $invoice->invoice_number }} with payment.</em></p>
      </div>
      <div class="well">
        <h3>Terms</h3>
        <ul>
          <li>Payment due by {{ $invoice->due_on?->format('F j, Y') ?? 'the date above' }}.</li>
          <li>All amounts in {{ $currency }}.</li>
        </ul>
      </div>
    </div>

    @if(filled($invoice->late_fee_terms_snapshot))
      <p class="fine-print">{{ $invoice->late_fee_terms_snapshot }}</p>
    @endif

  </div>

  <div class="doc-footer">
    <span class="footer-name">{{ $business['name'] ?? '' }}@if(!empty($business['contact_email'])) — {{ $business['contact_email'] }}@endif</span>
    <span>{{ $invoice->invoice_number }}</span>
  </div>

</div>

</body>
</html>
