@php
    $money = fn ($v): string => '$'.number_format((float) $v, 2);
@endphp
Drafts awaiting approval — {{ $businessName }}

{{ $drafts->count() }} draft{{ $drafts->count() === 1 ? '' : 's' }} totalling {{ $money($total) }} {{ $drafts->count() === 1 ? 'has' : 'have' }} not been approved.
Until {{ $drafts->count() === 1 ? 'it is' : 'they are' }}, this is work you have done and not billed for.

@foreach($drafts as $draft)
- {{ $draft->client_snapshot['name'] ?? $draft->client->name }} ({{ $draft->invoice_number }}, {{ $draft->created_at->diffForHumans() }}): {{ $money($draft->total) }} {{ $draft->issue_currency }}
@endforeach

Review drafts: {{ $listUrl }}

This repeats daily until each draft is approved or voided.
