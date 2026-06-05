<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Ready for Review</title>
</head>
<body style="font-family: sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>Invoice Ready for Review</h2>
    <p>A new invoice draft is ready for your review.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Invoice</strong></td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Client</strong></td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $invoice->client->name }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Period</strong></td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $invoice->period_start->format('F Y') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px;"><strong>Total</strong></td>
            <td style="padding: 8px;">{{ $invoice->issue_currency }} {{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>
    <p>Please log in to the admin panel to review and approve.</p>
</body>
</html>
