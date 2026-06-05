@php
    $eName = e($name);
    $eEmail = e($email);
    $eCompany = $company !== '' ? e($company) : '<span style="color:#4b4e57">—</span>';
    $eType = $type !== '' ? e($type) : '<span style="color:#4b4e57">—</span>';
    $eMsg = nl2br(e($body));
    $copyNote = $copyToSelf ? "Yes — sender CC'd" : 'No';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Inquiry — {{ $ticket }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:28px 36px 24px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td><span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">KYLE FERGUSON</span></td>
              <td align="right"><span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.06em;color:#c0392b;">NEW INQUIRY</span></td>
            </tr>
          </table>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0;font-size:22px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;">{!! $eName !!} sent a message</h1>
          <p style="margin:8px 0 0;font-size:13px;color:#71747e;">{{ $date }}</p>
        </td>
      </tr>

      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">TICKET {{ $ticket }} · OPEN</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:30px 36px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:20px;border-bottom:1px solid #2b2e36;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">NAME</span>
                <span style="font-size:15px;color:#e9e9ec;">{!! $eName !!}</span>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0;border-bottom:1px solid #2b2e36;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">EMAIL</span>
                <a href="mailto:{{ $email }}" style="font-size:15px;color:#c0392b;text-decoration:none;">{!! $eEmail !!}</a>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">COMPANY</span>
                      <span style="font-size:15px;color:#e9e9ec;">{!! $eCompany !!}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">PROJECT TYPE</span>
                      <span style="font-size:15px;color:#e9e9ec;">{!! $eType !!}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0 0;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:12px;">MESSAGE</span>
                <div style="font-size:15px;color:#e9e9ec;line-height:1.65;background:#15161a;border:1px solid #2b2e36;padding:16px 18px;">{!! $eMsg !!}</div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:16px 36px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td><span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.08em;color:#4b4e57;">COPY TO SENDER: {{ $copyNote }}</span></td>
              <td align="right"><span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.08em;color:#4b4e57;">{{ $ticket }}</span></td>
            </tr>
          </table>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
