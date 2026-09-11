@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
@endphp
Drafts ready for review — {{ $businessName }}, {{ $periodLabel }}

{{ $invoices->count() }} draft{{ $invoices->count() === 1 ? '' : 's' }} totalling {{ $money($total) }}.
Nothing reaches a client until you approve it.

DRAFTS
@foreach($invoices as $invoice)
- {{ $invoice->client_snapshot['name'] ?? $invoice->client->name }} ({{ $invoice->invoice_number }}): {{ $money($invoice->total) }} {{ $invoice->issue_currency }}
@endforeach

Review drafts: {{ $listUrl }}

A reminder repeats daily until each draft is approved or voided.
