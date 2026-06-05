<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { border-bottom: 3px solid {{ $invoice->business->brand_primary_color ?? '#1e40af' }}; padding-bottom: 20px; margin-bottom: 20px; }
        .logo { max-height: 60px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #f5f5f5; text-align: left; padding: 8px; border-bottom: 2px solid #ddd; }
        td { padding: 8px; border-bottom: 1px solid #eee; }
        .total-row td { font-weight: bold; border-top: 2px solid #333; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        @if($invoice->business->logo_path)
            <img src="{{ Storage::url($invoice->business->logo_path) }}" class="logo" alt="{{ $invoice->business->name }}">
        @else
            <h2>{{ $invoice->business->name }}</h2>
        @endif
    </div>

    <h1>Invoice {{ $invoice->invoice_number }}</h1>

    <table>
        <tr>
            <td><strong>Billed to</strong></td>
            <td>{{ $invoice->client->name }}<br>{{ $invoice->client->billing_address }}</td>
        </tr>
        <tr>
            <td><strong>Period</strong></td>
            <td>{{ $invoice->period_start->format('F 1, Y') }} – {{ $invoice->period_end->format('F j, Y') }}</td>
        </tr>
        <tr>
            <td><strong>Issue date</strong></td>
            <td>{{ $invoice->sent_at?->format('F j, Y') ?? now()->format('F j, Y') }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right;">Amount ({{ $invoice->issue_currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
                <tr>
                    <td>{{ $line->label }}</td>
                    <td style="text-align: right;">{{ number_format($line->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>Total ({{ $invoice->issue_currency }})</td>
                <td style="text-align: right;">{{ number_format($invoice->total, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($invoice->hosted_view_token)
        <p>
            <a href="{{ url('/invoices/'.$invoice->hosted_view_token) }}" style="color: {{ $invoice->business->brand_primary_color ?? '#1e40af' }};">
                View this invoice online →
            </a>
        </p>
    @endif

    @if($invoice->late_fee_terms_snapshot)
        <div class="footer">
            <p>{{ $invoice->late_fee_terms_snapshot }}</p>
        </div>
    @endif
</body>
</html>
