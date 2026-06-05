@props([
    'type' => 'info',
    'dismissible' => false,
    'title' => null,
])

@php
    $iconMap = [
        'success'   => 'circle-check',
        'danger'    => 'alert-circle',
        'warning'   => 'alert-triangle',
        'info'      => 'info',
        'primary'   => 'lightbulb',
        'secondary' => 'info',
    ];
    $icon = $iconMap[$type] ?? 'info';
@endphp

<div {{ $attributes->merge(['class' => 'alert alert-' . $type . ' flex items-start gap-3']) }}
     role="alert">
    <i data-lucide="{{ $icon }}" class="size-5 shrink-0 mt-0.5"></i>

    <div class="grow">
        @if($title)
            <p class="font-semibold mb-0.5">{{ $title }}</p>
        @endif
        {{ $slot }}
    </div>

    @if($dismissible)
        <button type="button"
                class="ms-auto shrink-0"
                data-hs-remove-element="this.closest('[role=alert]')"
                aria-label="Close">
            <i data-lucide="x" class="size-4"></i>
        </button>
    @endif
</div>
