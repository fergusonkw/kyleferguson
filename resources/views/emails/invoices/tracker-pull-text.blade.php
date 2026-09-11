@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
    $firstName = trim(explode(' ', trim((string) ($invoice->client_snapshot['contact_name'] ?? '')))[0] ?? '');
    // Built here rather than with @if/@else inline: Blade will not compile a
    // directive that a word character butts against, so `here@else` would pass
    // straight through into the sent mail as literal text.
    $greeting = $firstName !== '' ? $firstName.', here' : 'Here';
@endphp
Invoice {{ $invoice->invoice_number }} from {{ $businessName }}

{{ $greeting }} is invoice {{ $invoice->invoice_number }} for {{ $periodLabel }}. The PDF is attached.

AMOUNT DUE{{ $invoice->due_on ? ' BY '.strtoupper($invoice->due_on->format('F j, Y')) : '' }}
{{ $money($invoice->total) }} {{ $invoice->issue_currency }}

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
