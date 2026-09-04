<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice generation needs attention — {{ $periodLabel }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">{{ $businessName }}</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">Generation needs<br>attention.</h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:440px;">
            {{ count($failures) }} client{{ count($failures) === 1 ? '' : 's' }} could not be invoiced for
            {{ $periodLabel }}. Work billed to {{ count($failures) === 1 ? 'them' : 'them' }} is not on any draft.
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">{{ strtoupper($periodLabel) }} · {{ count($failures) }} NOT GENERATED</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          @foreach($failures as $client => $reason)
            <div style="padding:10px 0;border-bottom:1px solid #2b2e36;">
              <div style="font-size:14px;color:#e9e9ec;">{{ $client }}</div>
              <div style="font-size:12px;color:#71747e;line-height:1.5;margin-top:3px;">{{ $reason }}</div>
            </div>
          @endforeach
        </td>
      </tr>

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:28px 36px;">
          <a href="{{ $listUrl }}" style="display:inline-block;background:#c0392b;color:#fff;text-decoration:none;padding:12px 24px;font-size:14px;font-weight:600;">Open invoices</a>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px;">
          <p style="margin:0;font-size:12px;color:#4b4e57;line-height:1.6;">
            Fix the cause and generate the draft by hand, or wait for tomorrow's run.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
