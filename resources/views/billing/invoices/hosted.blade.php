<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }} — {{ $invoice->business->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; margin: 0; background: #f9fafb; }
        .container { max-width: 720px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); overflow: hidden; }
        .header { padding: 30px 40px; border-bottom: 3px solid var(--brand-color, #1e40af); display: flex; justify-content: space-between; align-items: flex-start; }
        .status-banner { background: #f0f9ff; border-bottom: 1px solid #bae6fd; padding: 12px 40px; font-size: 13px; color: #0369a1; }
        .body { padding: 30px 40px; }
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { text-align: left; padding: 10px 12px; border-bottom: 2px solid #e5e7eb; font-size: 12px; text-transform: uppercase; color: #6b7280; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; }
        .total-row td { font-weight: bold; border-top: 2px solid #374151; border-bottom: none; padding-top: 14px; }
        .footer { padding: 20px 40px; background: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; }
        @media (max-width: 600px) {
            .container { margin: 0; border-radius: 0; }
            .header, .body, .footer { padding: 20px; }
            .meta-row { flex-direction: column; gap: 12px; }
        }
    </style>
</head>
<body>
<div class="container" style="--brand-color: {{ $invoice->business->brand_primary_color ?? '#1e40af' }}">
    <div class="header">
        <div>
            @if($invoice->business->logo_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($invoice->business->logo_path) }}"
                    style="max-height: 50px;" alt="{{ $invoice->business->name }}">
            @else
                <h2 style="margin: 0; font-size: 20px;">{{ $invoice->business->name }}</h2>
            @endif
        </div>
        <div style="text-align: right;">
            <p style="margin: 0; font-size: 24px; font-weight: 700; color: #111;">INVOICE</p>
            <p style="margin: 4px 0 0; color: #6b7280;">{{ $invoice->invoice_number }}</p>
        </div>
    </div>

    @php
        $totalPaid = $invoice->totalPaid();
        $balance = max(0, $invoice->total - $totalPaid);
    @endphp

    @if($invoice->status === \App\Enums\Billing\InvoiceStatus::Paid)
        <div class="status-banner" style="background: #f0fdf4; border-color: #86efac; color: #166534;">
            ✓ This invoice has been paid in full. Thank you!
        </div>
    @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::PartiallyPaid)
        <div class="status-banner">
            Partially paid — {{ $invoice->issue_currency }} {{ number_format($totalPaid, 2) }} received.
            Balance due: {{ $invoice->issue_currency }} {{ number_format($balance, 2) }}
        </div>
    @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Sent)
        <div class="status-banner">
            Amount due: <strong>{{ $invoice->issue_currency }} {{ number_format($invoice->total, 2) }}</strong>
        </div>
    @endif

    <div class="body">
        <div class="meta-row">
            <div>
                <p style="margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: #9ca3af;">Billed to</p>
                <p style="margin: 0; font-weight: 600;">{{ $invoice->client->name }}</p>
                @if($invoice->client->billing_address)
                    <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280; white-space: pre-line;">{{ $invoice->client->billing_address }}</p>
                @endif
            </div>
            <div style="text-align: right;">
                <p style="margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: #9ca3af;">Details</p>
                <p style="margin: 0; font-size: 13px;">{{ $invoice->period_start->format('F Y') }}</p>
                <p style="margin: 2px 0 0; font-size: 13px; color: #6b7280;">Currency: {{ $invoice->issue_currency }}</p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: right; width: 140px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->lines as $line)
                    <tr>
                        <td>{{ $line->label }}</td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums;">
                            {{ number_format($line->amount, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td>Total {{ $invoice->issue_currency }}</td>
                    <td style="text-align: right;">{{ number_format($invoice->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if($invoice->late_fee_terms_snapshot)
        <div class="footer">
            {{ $invoice->late_fee_terms_snapshot }}
        </div>
    @endif
</div>
</body>
</html>
