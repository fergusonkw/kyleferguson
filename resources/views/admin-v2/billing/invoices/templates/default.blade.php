<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: sans-serif; color: #333; margin: 0; padding: 40px; }
        .header { display: flex; justify-content: space-between; border-bottom: 3px solid {{ $invoice->business->brand_primary_color ?? '#1e40af' }}; padding-bottom: 20px; margin-bottom: 30px; }
        h1 { margin: 0 0 5px; font-size: 28px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #f9f9f9; text-align: left; padding: 10px 12px; border-bottom: 2px solid #ddd; font-size: 12px; text-transform: uppercase; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; }
        .total-row td { font-weight: bold; font-size: 16px; border-top: 2px solid #333; border-bottom: none; }
        .meta { font-size: 13px; color: #666; margin-bottom: 4px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; font-size: 11px; color: #888; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            @if($invoice->business->logo_path)
                <img src="{{ Storage::url($invoice->business->logo_path) }}" style="max-height: 60px;" alt="{{ $invoice->business->name }}">
            @else
                <h2 style="margin: 0;">{{ $invoice->business->name }}</h2>
                @if($invoice->business->legal_name)
                    <p style="margin: 4px 0 0; font-size: 13px; color: #666;">{{ $invoice->business->legal_name }}</p>
                @endif
            @endif
        </div>
        <div style="text-align: right;">
            <h1>INVOICE</h1>
            <p class="meta">{{ $invoice->invoice_number }}</p>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; margin-bottom: 30px;">
        <div>
            <p class="meta" style="font-weight: bold; margin-bottom: 6px;">BILLED TO</p>
            <p style="margin: 0; font-weight: 600;">{{ $invoice->client->name }}</p>
            @if($invoice->client->billing_address)
                <p style="margin: 4px 0 0; white-space: pre-line; font-size: 13px;">{{ $invoice->client->billing_address }}</p>
            @endif
        </div>
        <div style="text-align: right;">
            <table style="width: auto; margin: 0; font-size: 13px;">
                <tr>
                    <td style="padding: 4px 8px 4px 0; border: none; color: #666;">Period</td>
                    <td style="padding: 4px 0; border: none;">{{ $invoice->period_start->format('F Y') }}</td>
                </tr>
                <tr>
                    <td style="padding: 4px 8px 4px 0; border: none; color: #666;">Due</td>
                    <td style="padding: 4px 0; border: none;">{{ $invoice->period_end->addDays(30)->format('F j, Y') }}</td>
                </tr>
                <tr>
                    <td style="padding: 4px 8px 4px 0; border: none; color: #666;">Currency</td>
                    <td style="padding: 4px 0; border: none;">{{ $invoice->issue_currency }}</td>
                </tr>
            </table>
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
                    <td style="text-align: right;">{{ number_format($line->amount, 2) }}</td>
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

    @if($invoice->late_fee_terms_snapshot)
        <div class="footer">
            {{ $invoice->late_fee_terms_snapshot }}
        </div>
    @endif
</body>
</html>
