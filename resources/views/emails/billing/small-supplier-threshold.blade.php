@php
    $money = fn (string $value): string => '$'.number_format((float) $value, 2).' CAD';
    $accent = $exceeded ? '#c0392b' : '#b26b00';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GST/HST small-supplier threshold — {{ $assessment->entity->name }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">{{ $assessment->entity->name }}</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          @if($exceeded)
            <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">The small-supplier<br>threshold has been passed.</h1>
            <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:460px;">
              Taxable supplies passed $30,000 on the {{ strtolower($assessment->exceededBy?->label() ?? 'four-quarter') }} test.
              That generally means registering for GST/HST and charging it from a set date — sooner under
              the single-quarter test. Confirm the dates with your accountant before the next invoice goes out.
            </p>
          @else
            <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">Approaching the<br>small-supplier threshold.</h1>
            <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:460px;">
              Taxable supplies have reached {{ $assessment->percentUsed() }}% of the $30,000 GST/HST
              threshold. This is the lead time to decide on registering before it is crossed.
            </p>
          @endif
        </td>
      </tr>

      <tr>
        <td style="background:{{ $accent }};padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">{{ strtoupper($assessment->level->label()) }} · {{ $assessment->percentUsed() }}% USED</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:20px;">TAXABLE SUPPLIES BY QUARTER</span>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            @foreach($assessment->quarters as $quarter)
              <tr>
                <td style="padding-bottom:12px;font-size:14px;color:#71747e;">{{ $quarter['label'] }}{{ $quarter['current'] ? ' (to date)' : '' }}</td>
                <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $money($quarter['total']) }}</td>
              </tr>
            @endforeach
            <tr>
              <td style="padding-top:12px;border-top:1px solid #2b2e36;font-size:14px;color:#e9e9ec;font-weight:600;">Four quarters</td>
              <td align="right" style="padding-top:12px;border-top:1px solid #2b2e36;font-size:14px;color:#e9e9ec;font-weight:600;">{{ $money($assessment->fourQuarterTotal) }}</td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:20px 36px;">
          <p style="margin:0;font-size:13px;color:#71747e;line-height:1.6;">
            Counts every business under
            @if($assessment->includesAssociates())
              {{ implode(', ', $assessment->entityNames) }} (associated entities are counted together)
            @else
              {{ $assessment->entity->name }}
            @endif
            — {{ implode(', ', $assessment->businessNames) }}.
            @if($assessment->uncountedInvoiceCount > 0)
              <br><span style="color:#e0803a;">{{ $assessment->uncountedInvoiceCount }} issued invoice(s) are not counted yet — their exchange rate is not available.</span>
            @endif
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:28px 36px;">
          <a href="{{ $dashboardUrl }}" style="display:inline-block;background:{{ $accent }};color:#fff;text-decoration:none;padding:12px 24px;font-size:14px;font-weight:600;">Open billing dashboard</a>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px;">
          <p style="margin:0;font-size:12px;color:#4b4e57;line-height:1.6;">
            Sent once when the warning level ({{ $assessment->warningPercent }}%) is reached and once when the
            threshold is passed. Only invoices issued through this system are counted.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
