@props([
    'assessment',
])

{{--
    Where the current business's legal entity stands against the GST/HST
    small-supplier threshold. The figure covers every business under the
    entity and its associates, so it is the same on each of their dashboards.
--}}

@use('App\Enums\Billing\ThresholdLevel')

@php
    $money = fn (string $value): string => '$'.number_format((float) $value, 2);
    $level = $assessment->level;
    $percent = $assessment->percentUsed();
    $barColor = match ($level) {
        ThresholdLevel::Exceeded => 'bg-danger',
        ThresholdLevel::Approaching => 'bg-warning',
        default => 'bg-success',
    };
    $peak = max(array_map(fn (array $q): float => (float) $q['total'], $assessment->quarters) ?: [0.0]);
    $peak = max($peak, 0.01);
@endphp

<div class="flex items-center justify-between mt-8 mb-3">
    <h5 class="text-sm font-semibold text-default-500 uppercase">GST/HST threshold</h5>
    <a href="{{ route('admin.billing.legal-entities.index') }}" class="text-sm text-primary">Legal entities →</a>
</div>

@if($level === ThresholdLevel::Exceeded)
    <x-admin-v2.alert type="danger" title="Past the $30,000 small-supplier threshold"
        message="Taxable supplies passed $30,000 on the {{ strtolower($assessment->exceededBy?->label() ?? '') }} test. GST/HST registration is likely required — confirm the effective date with your accountant before issuing the next invoice." />
@elseif($level === ThresholdLevel::Approaching)
    <x-admin-v2.alert type="warning"
        message="Taxable supplies are at {{ $percent }}% of the $30,000 threshold. Now is the time to decide on registering, before it is crossed." />
@endif

<x-admin-v2.card>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs text-default-400 uppercase">{{ $assessment->entity->name }}</p>
            @if($level === ThresholdLevel::Registered)
                <h4 class="text-lg font-semibold mt-1">Registered for GST/HST</h4>
                <p class="text-sm text-default-500">
                    Since {{ $assessment->entity->tax_registered_from->format('F j, Y') }} — the small-supplier threshold no longer applies.
                </p>
            @else
                <h4 class="text-2xl font-semibold mt-1">
                    {{ $money($assessment->fourQuarterTotal) }}
                    <span class="text-base font-normal text-default-400">of {{ $money($assessment->threshold) }} CAD</span>
                </h4>
                <p class="text-sm text-default-500">Over the last four calendar quarters, including this one to date.</p>
            @endif
        </div>
        <span class="badge bg-{{ $level->badgeColor() }}">{{ $level->label() }}</span>
    </div>

    @unless($level === ThresholdLevel::Registered)
        <div class="mt-4">
            <div class="relative h-2.5 w-full rounded-full bg-default-200 overflow-hidden">
                <div class="h-full {{ $barColor }}" style="width: {{ min(100, $percent) }}%"></div>
            </div>
            <div class="relative h-4">
                <span class="absolute -translate-x-1/2 text-[10px] text-default-400"
                      style="left: {{ $assessment->warningPercent }}%">▲ warn {{ $assessment->warningPercent }}%</span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-2">
            <div>
                <p class="text-xs text-default-400 uppercase">This quarter</p>
                <p class="text-lg font-semibold">{{ $money($assessment->currentQuarterTotal) }}</p>
                <p class="text-xs text-default-400">$30,000 in a single quarter also counts.</p>
            </div>
            <div>
                <p class="text-xs text-default-400 uppercase">Headroom</p>
                <p class="text-lg font-semibold">{{ $money($assessment->headroom()) }}</p>
                <p class="text-xs text-default-400">Before the four-quarter test trips.</p>
            </div>
            <div class="overflow-x-auto">
                <div class="flex items-end gap-2 h-16 min-w-[200px]">
                    @foreach($assessment->quarters as $quarter)
                        <div class="flex-1 flex flex-col items-center justify-end h-full gap-1"
                             title="{{ $quarter['label'] }}: {{ $money($quarter['total']) }}">
                            <div class="w-full rounded-t {{ $quarter['current'] ? 'bg-primary' : 'bg-primary/50' }}"
                                 style="height: {{ max(2, (int) round(((float) $quarter['total'] / $peak) * 80)) }}%"></div>
                            <span class="text-[10px] text-default-400">{{ $quarter['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <p class="text-xs text-default-400 mt-4">
            Counts approved and issued invoices from
            {{ implode(', ', $assessment->businessNames) }}@if($assessment->includesAssociates()), including associated
            {{ implode(', ', array_slice($assessment->entityNames, 1)) }}@endif,
            in CAD at the invoice period's Bank of Canada rate. Sales made outside this system are not included.
            @if($assessment->uncountedInvoiceCount > 0)
                <span class="text-warning">{{ $assessment->uncountedInvoiceCount }} invoice(s) are not counted yet while their exchange rate is unavailable.</span>
            @endif
        </p>
    @endunless
</x-admin-v2.card>
