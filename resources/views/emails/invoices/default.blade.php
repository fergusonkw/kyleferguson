@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
    $firstName = trim(explode(' ', trim((string) ($invoice->client_snapshot['contact_name'] ?? '')))[0] ?? '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:{{ $charcoal }};border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">{{ $businessName }}</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">
            Invoice {{ $invoice->invoice_number }}
          </h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:440px;">
            @if($firstName !== ''){{ $firstName }}, here@else Here @endif is your invoice for {{ $periodLabel }}.
            The PDF is attached, and you can view it online any time using the link below.
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:{{ $accent }};padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">
            {{ $money($invoice->total) }} {{ $invoice->issue_currency }} DUE{{ $invoice->due_on ? ' '.strtoupper($invoice->due_on->format('M j, Y')) : '' }}
          </span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:20px;">SUMMARY</span>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Billed to</td>
              <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $clientName }}</td>
            </tr>
            <tr>
              <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Billing period</td>
              <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $periodLabel }}</td>
            </tr>
            @if($invoice->due_on)
              <tr>
                <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Payment due</td>
                <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $invoice->due_on->format('F j, Y') }}</td>
              </tr>
            @endif
            <tr>
              <td style="padding-top:12px;border-top:1px solid #2b2e36;font-size:14px;color:#71747e;">Total</td>
              <td align="right" style="padding-top:12px;border-top:1px solid #2b2e36;font-size:15px;color:#e9e9ec;font-weight:600;">
                {{ $money($invoice->total) }} {{ $invoice->issue_currency }}
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="background:{{ $charcoal }};border:1px solid #2b2e36;border-top:none;padding:28px 36px;">
          <a href="{{ $hostedUrl }}" style="display:inline-block;background:{{ $accent }};color:#fff;text-decoration:none;padding:12px 24px;font-size:14px;font-weight:600;">View invoice online</a>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px;">
          <p style="margin:0 0 8px;font-size:12px;color:#4b4e57;line-height:1.6;">
            Please reference {{ $invoice->invoice_number }} with payment.
            @if($contactEmail)
              Questions? Reply to this email or write to
              <a href="mailto:{{ $contactEmail }}" style="color:#71747e;">{{ $contactEmail }}</a>.
            @endif
          </p>
          @if(filled($invoice->late_fee_terms_snapshot))
            <p style="margin:0;font-size:12px;color:#4b4e57;line-height:1.6;">{{ $invoice->late_fee_terms_snapshot }}</p>
          @endif
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
