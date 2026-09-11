@php
    $money = fn (string $value): string => '$'.number_format((float) $value, 2).' CAD';
@endphp
{{ $exceeded ? 'The small-supplier threshold has been passed' : 'Approaching the small-supplier threshold' }} — {{ $assessment->entity->name }}

@if($exceeded)
Taxable supplies passed $30,000 on the {{ strtolower($assessment->exceededBy?->label() ?? 'four-quarter') }} test.
That generally means registering for GST/HST and charging it from a set date —
sooner under the single-quarter test. Confirm the dates with your accountant
before the next invoice goes out.
@else
Taxable supplies have reached {{ $assessment->percentUsed() }}% of the $30,000 GST/HST
threshold. This is the lead time to decide on registering before it is crossed.
@endif

TAXABLE SUPPLIES BY QUARTER
@foreach($assessment->quarters as $quarter)
{{ str_pad($quarter['label'].($quarter['current'] ? ' (to date)' : '').':', 24) }}{{ $money($quarter['total']) }}
@endforeach
{{ str_pad('Four quarters:', 24) }}{{ $money($assessment->fourQuarterTotal) }}

Counts: {{ implode(', ', $assessment->businessNames) }}@if($assessment->includesAssociates()) (with associated entities {{ implode(', ', $assessment->entityNames) }})@endif.
@if($assessment->uncountedInvoiceCount > 0)
{{ $assessment->uncountedInvoiceCount }} issued invoice(s) are not counted yet — their exchange rate is not available.
@endif

Open the billing dashboard: {{ $dashboardUrl }}

Only invoices issued through this system are counted.

— Kyle
kyleferguson.ca
