<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pending Invoice Drafts</title>
</head>
<body style="font-family: sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>Pending Invoice Drafts — {{ $business->name }}</h2>
    <p>The following invoice drafts are awaiting approval:</p>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 8px; text-align: left; border-bottom: 2px solid #ddd;">Invoice</th>
                <th style="padding: 8px; text-align: left; border-bottom: 2px solid #ddd;">Client</th>
                <th style="padding: 8px; text-align: left; border-bottom: 2px solid #ddd;">Period</th>
                <th style="padding: 8px; text-align: right; border-bottom: 2px solid #ddd;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($drafts as $draft)
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $draft->invoice_number }}</td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $draft->client->name }}</td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $draft->period_start->format('M Y') }}</td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: right;">{{ $draft->issue_currency }} {{ number_format($draft->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p>Please log in to the admin panel to review and approve these invoices.</p>
</body>
</html>
