{{--
    Tracker Pull invoice email.

    Mirrors the Tracker Pull document kit — light ground, white card, red
    accent, Inter — so the mail and the invoice it carries read as the same
    document. The kit itself is not inlined here: mail clients strip <style>
    unpredictably, so every rule is an inline attribute on a table, and the
    colours are taken from the invoice's own snapshot rather than the kit's
    tokens.

    Webfonts are deliberately absent. Mail clients block remote font fetches
    far more aggressively than they block images, so the stack falls through to
    whatever the reader's system has — the same chain the kit falls back to.

    Its plain-text companion is `tracker-pull-text.blade.php`.
--}}
@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
    $firstName = trim(explode(' ', trim((string) ($invoice->client_snapshot['contact_name'] ?? '')))[0] ?? '');
    // Built here rather than with @if/@else inline: Blade will not compile a
    // directive that a word character butts against, so `here@else` would pass
    // straight through into the sent mail as literal text.
    $greeting = $firstName !== '' ? $firstName.', here' : 'Here';
    $font = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:{{ $font }};font-size:14px;line-height:1.5;color:#111827;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F6;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #E5E7EB;border-radius:8px;">

      <tr>
        <td style="padding:32px 36px 24px;border-bottom:1px solid #E5E7EB;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="font-size:15px;font-weight:600;color:{{ $charcoal }};">{{ $businessName }}</td>
              <td align="right" style="font-size:22px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:{{ $accent }};line-height:1;">Invoice</td>
            </tr>
          </table>
          <p style="margin:20px 0 0;font-size:14px;color:#6B7280;line-height:1.6;">
            {{ $greeting }} is invoice
            <strong style="color:{{ $charcoal }};font-weight:600;">{{ $invoice->invoice_number }}</strong>
            for {{ $periodLabel }}. The PDF is attached, and you can view it online any time using the link below.
          </p>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 36px 0;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;">
            <tr>
              <td style="padding:14px 16px;">
                <div style="font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">
                  Amount due{{ $invoice->due_on ? ' by '.$invoice->due_on->format('F j, Y') : '' }}
                </div>
                <div style="font-size:24px;font-weight:700;line-height:1.1;color:{{ $accent }};">
                  {{ $money($invoice->total) }} {{ $invoice->issue_currency }}
                </div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 36px 0;">
          <div style="font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.06em;padding-bottom:6px;border-bottom:1px solid #E5E7EB;">Summary</div>
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
            <tr>
              <td style="padding-bottom:10px;font-size:14px;color:#6B7280;">Billed to</td>
              <td align="right" style="padding-bottom:10px;font-size:14px;color:#111827;">{{ $clientName }}</td>
            </tr>
            <tr>
              <td style="padding-bottom:10px;font-size:14px;color:#6B7280;">Billing period</td>
              <td align="right" style="padding-bottom:10px;font-size:14px;color:#111827;">{{ $periodLabel }}</td>
            </tr>
            @if($invoice->due_on)
              <tr>
                <td style="padding-bottom:10px;font-size:14px;color:#6B7280;">Payment due</td>
                <td align="right" style="padding-bottom:10px;font-size:14px;color:#111827;">{{ $invoice->due_on->format('F j, Y') }}</td>
              </tr>
            @endif
            <tr>
              <td style="padding-top:12px;border-top:1px solid #E5E7EB;font-size:15px;font-weight:700;color:#111827;">Total</td>
              <td align="right" style="padding-top:12px;border-top:1px solid #E5E7EB;font-size:16px;font-weight:700;color:{{ $accent }};">
                {{ $money($invoice->total) }} {{ $invoice->issue_currency }}
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:28px 36px;">
          <a href="{{ $hostedUrl }}" style="display:inline-block;background:{{ $accent }};color:#FFFFFF;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">View invoice online</a>
        </td>
      </tr>

      <tr>
        <td style="padding:18px 36px 24px;border-top:1px solid #E5E7EB;">
          <p style="margin:0 0 8px;font-size:12px;color:#9CA3AF;line-height:1.6;">
            Please reference {{ $invoice->invoice_number }} with payment.
            @if($contactEmail)
              Questions? Reply to this email or write to
              <a href="mailto:{{ $contactEmail }}" style="color:{{ $accent }};text-decoration:none;">{{ $contactEmail }}</a>.
            @endif
          </p>
          @if(filled($invoice->late_fee_terms_snapshot))
            <p style="margin:0;font-size:12px;color:#9CA3AF;line-height:1.6;">{{ $invoice->late_fee_terms_snapshot }}</p>
          @endif
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
