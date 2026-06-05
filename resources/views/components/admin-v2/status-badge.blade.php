@props([
    /**
     * Semantic colour: primary | secondary | info | success | warning | danger | default
     * Anything outside this list falls back to 'default'.
     */
    'color' => 'default',
    /**
     * Visual variant:
     *   'soft'    — translucent background, coloured text (the dominant pattern in admin-v2)
     *   'solid'   — solid background, white text (Bootstrap-ish; use for sidenav/topbar pills)
     *   'outline' — transparent background, coloured text and border
     */
    'variant' => 'soft',
    /** Optional Lucide icon name to render before the label. */
    'icon' => null,
])

@php
    $allowedColors = ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'default'];
    $safeColor = in_array($color, $allowedColors, true) ? $color : 'default';

    $variantClasses = match ($variant) {
        'solid' => "bg-{$safeColor} text-white",
        'outline' => "border border-{$safeColor} text-{$safeColor} bg-transparent",
        default => "bg-{$safeColor}/15 text-{$safeColor}",
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$variantClasses}"]) }}>
    @if($icon)
        <i data-lucide="{{ $icon }}" class="size-3 shrink-0"></i>
    @endif
    {{ $slot }}
</span>
