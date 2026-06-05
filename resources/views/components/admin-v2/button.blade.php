@props([
    'variant' => 'primary',
    'size' => 'default',
    'outline' => false,
    'icon' => null,
    'iconPosition' => 'left',
    'loading' => false,
    'disabled' => false,
])

@php
    $classes = 'btn';
    $classes .= $outline ? ' btn-outline-' . $variant : ' btn-' . $variant;
    $classes .= match($size) {
        'sm' => ' btn-sm',
        'lg' => ' btn-lg',
        default => '',
    };
    if ($disabled || $loading) {
        $classes .= ' disabled';
    }
@endphp

<button {{ $attributes->merge(['class' => $classes, 'type' => 'button']) }}
        @if($disabled || $loading) disabled @endif>
    @if($loading)
        <span class="inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent me-1.5" role="status" aria-hidden="true"></span>
    @elseif($icon && $iconPosition === 'left')
        <i data-lucide="{{ $icon }}" class="size-4 me-1.5 align-middle"></i>
    @endif

    {{ $slot }}

    @if($icon && $iconPosition === 'right' && !$loading)
        <i data-lucide="{{ $icon }}" class="size-4 ms-1.5 align-middle"></i>
    @endif
</button>
