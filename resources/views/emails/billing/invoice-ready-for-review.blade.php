@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoices ready for review — {{ $periodLabel }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">{{ $businessName }}</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">Drafts ready<br>for review.</h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:440px;">
            {{ $invoices->count() }} draft{{ $invoices->count() === 1 ? '' : 's' }} for {{ $periodLabel }}.
            Nothing reaches a client until you approve it.
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">{{ $money($total) }} ACROSS {{ $invoices->count() }} DRAFT{{ $invoices->count() === 1 ? '' : 'S' }}</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:16px;">DRAFTS</span>
          @foreach($invoices as $invoice)
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-bottom:1px solid #2b2e36;">
              <tr>
                <td style="padding:9px 0;font-size:14px;color:#e9e9ec;">
                  {{ $invoice->client_snapshot['name'] ?? $invoice->client->name }}
                  <span style="color:#4b4e57;font-family:monospace,monospace;font-size:11px;"> · {{ $invoice->invoice_number }}</span>
                </td>
                <td align="right" style="padding:9px 0;font-size:14px;color:#e9e9ec;">{{ $money($invoice->total) }} {{ $invoice->issue_currency }}</td>
              </tr>
            </table>
          @endforeach
        </td>
      </tr>

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:28px 36px;">
          <a href="{{ $listUrl }}" style="display:inline-block;background:#c0392b;color:#fff;text-decoration:none;padding:12px 24px;font-size:14px;font-weight:600;">Review drafts</a>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px;">
          <p style="margin:0;font-size:12px;color:#4b4e57;line-height:1.6;">
            A reminder repeats daily until each draft is approved or voided.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
