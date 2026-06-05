@props([
    'title' => null,
    'icon' => null,
    'primaryStatistic' => null,
    'primaryStatisticSuffix' => '',
    'primaryStatisticBadge' => null,
    'primaryStatisticBadgeColor' => 'success',
    'animateCounter' => true,
    'secondaryTitle' => null,
    'secondaryColor' => 'success',
    'detailStatistic' => null,
])

@php
    $statId = $animateCounter && is_numeric($primaryStatistic) ? 'stat-' . uniqid() : null;
@endphp

<x-admin-v2.card>
    @if($title)
        <h5 class="text-default-500 text-sm font-medium mb-0" title="{{ $title }}">{{ $title }}</h5>
    @endif
    <div class="flex items-center gap-3 my-3">
        @if($icon)
            <div class="size-12 shrink-0 rounded-full bg-light flex items-center justify-center">
                <i data-lucide="{{ $icon }}" class="size-6 text-primary"></i>
            </div>
        @endif
        <h3 class="text-2xl font-bold mb-0">
            @if($animateCounter && is_numeric($primaryStatistic))
                <span id="{{ $statId }}">{{ $primaryStatistic }}</span>
            @else
                <span>{{ $primaryStatistic }}</span>
            @endif
            @if($primaryStatisticSuffix)
                {{ $primaryStatisticSuffix }}
            @endif
        </h3>
        @if($primaryStatisticBadge)
            <span class="badge bg-{{ $primaryStatisticBadgeColor }}/15 text-{{ $primaryStatisticBadgeColor }} text-xs font-medium ms-auto">{{ $primaryStatisticBadge }}</span>
        @endif
    </div>
    @if($secondaryTitle || $detailStatistic)
        <p class="flex items-center justify-between mb-0 text-sm text-default-400">
            @if($secondaryTitle)
                <span class="flex items-center gap-1">
                    <span class="size-2 rounded-full bg-{{ $secondaryColor }}"></span>
                    {{ $secondaryTitle }}
                </span>
            @endif
            @if($detailStatistic)
                <span class="font-semibold text-default-700">{{ $detailStatistic }}</span>
            @endif
        </p>
    @endif
</x-admin-v2.card>

@if($statId)
<script>
(function() {
    'use strict';

    const elementId = '{{ $statId }}';
    const finalValue = {{ $primaryStatistic }};
    const duration = 1000;

    function initCounter() {
        const el = document.getElementById(elementId);
        if (!el) { return; }

        el.textContent = '0';

        const startTime = performance.now();
        const range = finalValue;

        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function updateCounter(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = progress * (2 - progress);
            const current = Math.round(range * eased);

            el.textContent = formatNumber(current);

            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                el.textContent = formatNumber(finalValue);
            }
        }

        requestAnimationFrame(updateCounter);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCounter);
    } else {
        initCounter();
    }
})();
</script>
@endif
