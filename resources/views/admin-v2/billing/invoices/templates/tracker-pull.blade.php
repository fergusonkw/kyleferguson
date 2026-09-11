{{--
    Tracker Pull invoice template.

    Markup written against the class names in the Tracker Pull document kit,
    whose CSS is vendored beside this file and refreshed with
    `php artisan billing:sync-document-kit`. The kit is the single source of
    truth for how the document looks; nothing here should restyle it. Anything
    this template needs that the kit does not carry — the client bar, the
    monogram, the per-business colours — is emitted as overrides *after* the
    kit, so the vendored copy stays byte-identical to upstream.

    Only the CSS crosses the repository boundary. Upstream's invoice.html is a
    static reference implementation of this markup; this file reimplements it
    against the same classes, because the conditionals below have to interleave
    with the very elements a generated block would need to own.

    Everything rendered comes from the invoice's own snapshots, never from the
    live business or client, so an invoice issued last year still renders as it
    was issued even after a rebrand or a change of address.
--}}
@php
    // Only the hosted client page supplies these; the PDF renders without
    // them, so the printed document stays the canonical record.
    $banner ??= null;
    $downloadUrl ??= null;
    // Absent when the business has no logo, or when the file behind a
    // snapshotted path has gone — the monogram stands in either way.
    $logoDataUri ??= null;
    // Empty rather than null: a template rendered without the stores still
    // produces valid CSS, it just falls back to system faces and no kit.
    $fontFaceCss ??= '';
    $kitCss ??= '';
    $hosted = $banner !== null || $downloadUrl !== null;

    $business = $invoice->business_snapshot ?? [];
    $client = $invoice->client_snapshot ?? [];
    $currency = $invoice->issue_currency;

    $money = fn ($value): string => ($value < 0 ? '−$' : '$').number_format(abs((float) $value), 2);

    // Null rather than a default: an unset colour must leave the kit's own
    // token alone rather than overwrite it with the same value.
    $accent = $business['brand_primary_color'] ?? null;
    $ink = $business['brand_secondary_color'] ?? null;

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

    // Qty and Unit Price columns only earn their place when something uses
    // them — two empty columns on a hosting-only invoice are just noise.
    $showRateColumns = $invoice->topLevelLines->contains(fn ($line): bool => $line->isMetered());

    // The kit gives each address line its own div, so a multi-line snapshot is
    // split rather than relying on white-space handling.
    $lines = function (?string $value): array {
        return $value === null
            ? []
            : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
    };

    // "Tracker Pull Media Inc." is a perfectly ordinary payee, and it already
    // ends the sentence it is dropped into.
    $fullStop = fn (string $value): string => str_ends_with(rtrim($value), '.') ? '' : '.';

    $issuerLines = array_merge($lines($business['address'] ?? null), array_filter([$business['contact_email'] ?? null]));
    $billToLines = array_merge(
        array_filter([$client['contact_name'] ?? null]),
        $lines($client['billing_address'] ?? null),
        array_filter([$client['contact_email'] ?? null]),
    );
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if($hosted)
  <meta name="robots" content="noindex, nofollow">
@endif
<title>{{ $invoice->invoice_number }} — {{ $business['name'] ?? 'Invoice' }}</title>
<style>
{{-- The vendored kit, inlined verbatim. Browsershot renders from a bare HTML
     string with no document base and no promise of network access, so a
     stylesheet link would leave the same invoice printing two different ways
     depending on the host. Empty when the kit has not been vendored, in which
     case the overrides below still produce a legible, if plain, document. --}}
{!! $kitCss !!}
{!! $fontFaceCss !!}

  /* --- Per-business branding. Overrides the kit's tokens rather than
         editing it, so the vendored copy stays a clean diff against
         upstream and a second business can adopt this layout. --- */
  :root {
@if($accent !== null)
    --brand-red: {{ $accent }};
@endif
@if($ink !== null)
    --text: {{ $ink }};
@endif
  }

  /* --- What the kit does not carry, because only an invoice needs it. --- */

  /* Stands in for the logo when a business has none. */
  .monogram {
    width: 44px; height: 44px; flex: 0 0 auto;
    border: 1px solid var(--border);
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 15px; color: var(--text);
  }
  .header-text .badge { margin-top: 10px; }

  /* Paid is the one tone the kit's callouts do not cover. */
  .notes.paid { background: var(--ok-soft); border-color: #A7F3D0; }
  .notes.paid .notes-label { color: var(--ok); }

  .fine-print { font-size: 11px; color: var(--text-faint); margin-bottom: 28px; }

  /* Hosted client page only — the kit hides .screen-only on paper. */
  .client-bar {
    max-width: 8.5in; margin: 24px auto -12px;
    display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
  }
  .client-bar .notes { flex: 1 1 320px; margin-bottom: 0; }
  .client-bar .download {
    display: inline-block; padding: 11px 20px; border-radius: 8px;
    background: var(--brand-red); color: #fff;
    text-decoration: none; font-size: 14px; font-weight: 600;
  }
</style>
</head>
<body>

@if($hosted)
  <div class="client-bar screen-only">
    @if($banner !== null)
      <div class="notes {{ $banner['tone'] === 'overdue' ? 'accent' : $banner['tone'] }}">
        <div class="notes-body">{{ $banner['message'] }}</div>
      </div>
    @else
      <div style="flex: 1 1 320px;"></div>
    @endif
    @if($downloadUrl !== null)
      <a class="download" href="{{ $downloadUrl }}">Download PDF</a>
    @endif
  </div>
@endif

<div class="page">

  <!-- ===== Header ===== -->
  <div class="header">
    @if($logoDataUri !== null)
      <img class="logo brand-logo" src="{{ $logoDataUri }}" alt="{{ $business['name'] ?? '' }}">
    @else
      <div class="monogram">{{ $monogram }}</div>
    @endif
    <div class="header-text">
      <h1 class="title">Invoice</h1>
      @if($invoice->status === \App\Enums\Billing\InvoiceStatus::Void)
        <span class="badge alert">Void</span>
      @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Paid)
        <span class="badge ok">Paid</span>
      @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Draft)
        <span class="badge">Draft</span>
      @endif
    </div>
  </div>

  <!-- ===== Meta ===== -->
  <div class="meta">
    <div class="meta-cell">
      <div class="meta-label">Invoice #</div>
      <div class="meta-value">{{ $invoice->invoice_number }}</div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Issue date</div>
      <div class="meta-value">{{ $invoice->issued_on?->format('M j, Y') ?? '—' }}</div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Due date</div>
      <div class="meta-value">{{ $invoice->due_on?->format('M j, Y') ?? '—' }}</div>
    </div>
    <div class="meta-cell">
      <div class="meta-label">Billing period</div>
      <div class="meta-value">{{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}</div>
    </div>
  </div>

  <!-- ===== Parties ===== -->
  <div class="parties">
    <div>
      <div class="party-label">From</div>
      <div class="party-name">{{ $issuerName }}</div>
      <div class="party-lines">
        @foreach($issuerLines as $line)
          <div>{{ $line }}</div>
        @endforeach
      </div>
    </div>
    <div>
      <div class="party-label">Bill to</div>
      <div class="party-name">{{ $client['name'] ?? '' }}</div>
      <div class="party-lines">
        @foreach($billToLines as $line)
          <div>{{ $line }}</div>
        @endforeach
      </div>
    </div>
  </div>

  <!-- ===== Line items ===== -->
  <table class="items">
    <thead>
      <tr>
        <th>Description</th>
        @if($showRateColumns)
          <th class="num" style="width: 90px;">Qty</th>
          <th class="num" style="width: 110px;">Unit Price</th>
        @endif
        <th class="num" style="width: 120px;">Amount</th>
      </tr>
    </thead>
    <tbody>
      @forelse($invoice->topLevelLines as $line)
        <tr>
          <td>
            <div class="item-desc">{{ $line->label }}</div>
            @if(filled($line->description))
              <div class="item-sub">{{ $line->description }}</div>
            @endif
            @if($line->children->isNotEmpty())
              <div class="item-sub">
                @foreach($line->children as $child)
                  <span class="nowrap">{{ $child->label }} {{ $money($child->amount) }}</span>@if(! $loop->last)&nbsp;&nbsp;@endif
                @endforeach
              </div>
            @endif
            @if($line->conversionNote())
              <div class="item-sub">{{ $line->conversionNote() }}</div>
            @endif
            {{-- Without the Qty/Unit Price columns, a metered line still shows
                 its working inline rather than losing it. --}}
            @if(! $showRateColumns && $line->rateNote())
              <div class="item-sub">{{ $line->rateNote() }}</div>
            @endif
          </td>
          @if($showRateColumns)
            <td class="num">{{ $line->quantityLabel() ?? '—' }}</td>
            <td class="num">{{ $line->unit_rate !== null ? $money($line->unit_rate) : '—' }}</td>
          @endif
          <td class="num">{{ $money($line->amount) }}</td>
        </tr>
      @empty
        <tr>
          <td class="empty" colspan="{{ $showRateColumns ? 4 : 2 }}">No billable items for this period.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  <!-- ===== Totals ===== -->
  <div class="totals">
    <div class="totals-table">
      <div class="totals-row">
        <div class="label">Subtotal</div>
        <div class="value">{{ $money($invoice->subtotal) }}</div>
      </div>
      @if(bccomp($invoice->tax_total, '0.00', 2) === 1)
        <div class="totals-row">
          <div class="label">Tax</div>
          <div class="value">{{ $money($invoice->tax_total) }}</div>
        </div>
      @endif
      @if($hasPayments)
        <div class="totals-row">
          <div class="label">Paid</div>
          <div class="value">−{{ $money($amountPaid) }}</div>
        </div>
      @endif
      <div class="totals-row total">
        <div class="label">{{ $hasPayments ? 'Balance Due' : 'Total' }} ({{ $currency }})</div>
        <div class="value">{{ $money($hasPayments ? $invoice->balanceDue() : $invoice->total) }}</div>
      </div>
    </div>
  </div>

  <!-- ===== Notes ===== -->
  <div class="notes">
    <div class="notes-label">Payment instructions</div>
    <div class="notes-body">
      <p>Payable by Interac e-Transfer to <strong>{{ $business['contact_email'] ?? '' }}</strong>.</p>
      @if(!empty($business['cheque_payable_to']))
        <p>Cheques payable to <strong>{{ $business['cheque_payable_to'] }}</strong>{{ $fullStop($business['cheque_payable_to']) }}</p>
      @endif
      <p>Please reference {{ $invoice->invoice_number }} with your payment.</p>
    </div>
  </div>

  <div class="notes">
    <div class="notes-label">Terms</div>
    <div class="notes-body">
      <ul>
        <li>Payment due by {{ $invoice->due_on?->format('F j, Y') ?? 'the date above' }}.</li>
        <li>All amounts in {{ $currency }}.</li>
      </ul>
    </div>
  </div>

  @if(filled($invoice->late_fee_terms_snapshot))
    <p class="fine-print">{{ $invoice->late_fee_terms_snapshot }}</p>
  @endif

  <!-- ===== Footer ===== -->
  <div class="footer">
    <div>{{ $business['name'] ?? '' }}@if(!empty($business['contact_email'])) — {{ $business['contact_email'] }}@endif</div>
    <div>{{ $invoice->invoice_number }}</div>
  </div>

</div>
</body>
</html>
