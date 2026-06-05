@props([
    'canvasId',
    'title' => 'Offcanvas Title',
    'size' => 'default',
    'placement' => 'end',
    'backdrop' => true,
    'keyboard' => true,
    'scroll' => false,
])

@php
    $width = match($size) {
        'xlarge' => 'max-w-2xl',
        'large'  => 'max-w-sm',
        'small'  => 'max-w-xs',
        default  => 'max-w-sm',
    };

    // Placement determines position, translate, open-state class, and which edge gets a border.
    [$positionClasses, $translateClosed, $translateOpen, $edgeBorder] = match($placement) {
        'start'  => ['start-0 top-0 h-full', '-translate-x-full', 'hs-overlay-open:translate-x-0', 'border-e'],
        'top'    => ['top-0 inset-x-0 max-h-60',  '-translate-y-full', 'hs-overlay-open:translate-y-0', 'border-b'],
        'bottom' => ['bottom-0 inset-x-0 max-h-60', 'translate-y-full', 'hs-overlay-open:translate-y-0', 'border-t'],
        default  => ['end-0 top-0 h-full',  'translate-x-full',  'hs-overlay-open:translate-x-0', 'border-s'],
    };
@endphp

<div class="hs-overlay {{ $positionClasses }} {{ $width }} {{ $translateClosed }} {{ $translateOpen }} fixed z-80 hidden w-full transform transition-all duration-300 bg-card border-default-300 {{ $edgeBorder }} flex flex-col"
     id="{{ $canvasId }}"
     role="dialog"
     tabindex="-1"
     @if(!$backdrop) data-hs-overlay-backdrop="false" @endif
     @if(!$keyboard) data-hs-overlay-keyboard="false" @endif>
    <div class="flex items-center justify-between p-5 border-b border-default-200">
        <h5 class="font-semibold" id="{{ $canvasId }}Label">{{ $title }}</h5>
        <div class="flex items-center gap-2">
            @if(isset($actions))
                {{ $actions }}
            @endif
            <button type="button"
                    class="close-icon"
                    data-hs-overlay="#{{ $canvasId }}"
                    aria-label="Close">
                <i data-lucide="x" class="size-5"></i>
            </button>
        </div>
    </div>
    <div class="p-5 overflow-y-auto grow">
        {{ $slot }}
    </div>
</div>
