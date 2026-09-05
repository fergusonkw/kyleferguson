@php
    $money = fn (float $value): string => '$'.number_format($value, 2).' USD';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reconciliation needs attention — {{ $periodLabel }}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">{{ $businessName }}</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">Reconciliation needs<br>attention.</h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:440px;">
            Some costs for {{ $periodLabel }} can't be accounted for yet. Anything left
            unattributed won't reach a client invoice.
          </p>
        </td>
      </tr>

      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">{{ strtoupper($periodLabel) }} · NEEDS REVIEW</span>
        </td>
      </tr>

      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:20px;">COST BASIS</span>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Attributed to projects</td>
              <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $money($summary->attributedCost) }}</td>
            </tr>
            <tr>
              <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Unattributed</td>
              <td align="right" style="padding-bottom:12px;font-size:14px;color:{{ $summary->unattributedCost > 0 ? '#e0803a' : '#e9e9ec' }};">{{ $money($summary->unattributedCost) }}</td>
            </tr>
            <tr>
              <td style="padding-bottom:12px;font-size:14px;color:#71747e;">Overhead (not billed on)</td>
              <td align="right" style="padding-bottom:12px;font-size:14px;color:#e9e9ec;">{{ $money($summary->overheadCost) }}</td>
            </tr>
            <tr>
              <td style="padding-top:12px;border-top:1px solid #2b2e36;font-size:14px;color:#71747e;">Cost gap</td>
              <td align="right" style="padding-top:12px;border-top:1px solid #2b2e36;font-size:14px;color:#e9e9ec;">
                @if($summary->hasCostGap())
                  {{ $money($summary->costGap) }}
                @else
                  <span style="color:#4b4e57;">—</span>
                @endif
              </td>
            </tr>
          </table>
        </td>
      </tr>

      @if($resources->isNotEmpty())
      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:24px 36px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:16px;">UNATTRIBUTED RESOURCES ({{ $resources->count() }})</span>
          @foreach($resources->take(15) as $resource)
            <div style="padding:8px 0;border-bottom:1px solid #2b2e36;font-size:14px;color:#e9e9ec;">
              {{ $resource->name ?? $resource->provider_resource_id }}
              <span style="color:#4b4e57;font-family:monospace,monospace;font-size:11px;"> · {{ $resource->resource_type }} · {{ $resource->costProvider->display_name }}</span>
            </div>
          @endforeach
          @if($resources->count() > 15)
            <p style="margin:12px 0 0;font-size:13px;color:#71747e;">…and {{ $resources->count() - 15 }} more.</p>
          @endif
        </td>
      </tr>
      @endif

      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:28px 36px;">
          <a href="{{ $reconciliationUrl }}" style="display:inline-block;background:#c0392b;color:#fff;text-decoration:none;padding:12px 24px;font-size:14px;font-weight:600;">Open reconciliation</a>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 36px;">
          <p style="margin:0;font-size:12px;color:#4b4e57;line-height:1.6;">
            Sent because unattributed costs or a non-zero gap were found for {{ $periodLabel }}.
            This alert stops once the period reconciles.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
