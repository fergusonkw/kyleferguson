@php
    $money = fn (float $value): string => '$'.number_format($value, 2).' USD';
@endphp
Reconciliation needs attention — {{ $businessName }}, {{ $periodLabel }}

Some costs for {{ $periodLabel }} can't be accounted for yet. Anything left
unattributed won't reach a client invoice.

COST BASIS
Attributed to projects:  {{ $money($summary->attributedCost) }}
Unattributed:            {{ $money($summary->unattributedCost) }}
Overhead (not billed):   {{ $money($summary->overheadCost) }}
Cost gap:                {{ $summary->hasCostGap() ? $money($summary->costGap) : '—' }}

@if($resources->isNotEmpty())
UNATTRIBUTED RESOURCES ({{ $resources->count() }})
@foreach($resources->take(15) as $resource)
- {{ $resource->name ?? $resource->provider_resource_id }} ({{ $resource->resource_type }}, {{ $resource->costProvider->display_name }})
@endforeach
@if($resources->count() > 15)
...and {{ $resources->count() - 15 }} more.
@endif
@endif

Open reconciliation: {{ $reconciliationUrl }}

— Kyle
kyleferguson.ca
