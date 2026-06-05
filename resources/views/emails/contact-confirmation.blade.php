@php
    $firstName = e(explode(' ', trim($name))[0]);
    $eName = e($name);
    $eEmail = e($email);
    $eCompany = $company !== '' ? e($company) : '<span style="color:#4b4e57">—</span>';
    $eType = $type !== '' ? e($type) : '<span style="color:#4b4e57">—</span>';
    $eMsg = nl2br(e($body));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Message received — {{ $ticket }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">KYLE FERGUSON</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">Message received,<br>{!! $firstName !!}.</h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:420px;">
            I've logged your inquiry and will be in touch at
            <a href="mailto:{{ $email }}" style="color:#e9e9ec;text-decoration:none;">{!! $eEmail !!}</a>
            — usually within a couple of business days.
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">TICKET {{ $ticket }} · LOGGED</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:20px;">YOUR SUBMISSION</span>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:16px;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">NAME</span>
                      <span style="font-size:14px;color:#b6b8bf;">{!! $eName !!}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">EMAIL</span>
                      <span style="font-size:14px;color:#b6b8bf;">{!! $eEmail !!}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 0;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">COMPANY</span>
                      <span style="font-size:14px;color:#b6b8bf;">{!! $eCompany !!}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">PROJECT TYPE</span>
                      <span style="font-size:14px;color:#b6b8bf;">{!! $eType !!}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding-top:16px;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:10px;">MESSAGE</span>
                <div style="font-size:14px;color:#b6b8bf;line-height:1.65;background:#15161a;border:1px solid #2b2e36;padding:14px 16px;">{!! $eMsg !!}</div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:24px 36px;">
          <p style="margin:0 0 6px;font-size:13px;color:#71747e;">Need to add anything? Reply to this email and it'll reach me directly.</p>
          <p style="margin:0;font-size:13px;color:#4b4e57;">&mdash; Kyle</p>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px 0;">
          <p style="margin:0;font-family:monospace,monospace;font-size:10px;letter-spacing:0.06em;color:#2b2e36;text-align:center;">kyleferguson.ca &nbsp;·&nbsp; {{ $date }} &nbsp;·&nbsp; {{ $ticket }}</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
