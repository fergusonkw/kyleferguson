@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
    $firstName = trim(explode(' ', trim((string) ($invoice->client_snapshot['contact_name'] ?? '')))[0] ?? '');
@endphp
Invoice {{ $invoice->invoice_number }} from {{ $businessName }}

@if($firstName !== ''){{ $firstName }}, here@else Here @endif is your invoice for {{ $periodLabel }}. The PDF is attached.

Billed to:      {{ $clientName }}
Billing period: {{ $periodLabel }}
@if($invoice->due_on)
Payment due:    {{ $invoice->due_on->format('F j, Y') }}
@endif
Total:          {{ $money($invoice->total) }} {{ $invoice->issue_currency }}

View online: {{ $hostedUrl }}

Please reference {{ $invoice->invoice_number }} with payment.
@if($contactEmail)
Questions? Reply to this email or write to {{ $contactEmail }}.
@endif
@if(filled($invoice->late_fee_terms_snapshot))

{{ $invoice->late_fee_terms_snapshot }}
@endif

— {{ $businessName }}
