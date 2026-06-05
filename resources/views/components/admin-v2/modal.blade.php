@props([
    'id',
    'title' => 'Modal',
    'size' => 'default',
    'centered' => true,
    'static' => false,
    'footer' => true,
])

@php
    $maxW = match($size) {
        'sm'  => 'max-w-sm',
        'lg'  => 'max-w-2xl',
        'xl'  => 'max-w-4xl',
        default => 'max-w-lg',
    };
    $justify = $centered ? 'items-center' : 'items-start';
@endphp

<div class="hs-overlay hs-overlay-open:opacity-100 hs-overlay-open:duration-500 pointer-events-none fixed start-0 top-0 z-80 hidden size-full overflow-x-hidden overflow-y-auto"
     id="{{ $id }}"
     role="dialog"
     tabindex="-1"
     @if($static) data-hs-overlay-keyboard="false" data-hs-overlay-backdrop-container="body" @endif>
    <div class="hs-overlay-animation-target flex {{ $justify }} justify-center m-3 min-h-[calc(100%-1.5rem)]">
        <div class="card pointer-events-auto flex w-full flex-col {{ $maxW }}">
            <div class="border-b border-default-200 flex items-center justify-between p-5">
                <h3 class="card-title" id="{{ $id }}Label">{{ $title }}</h3>
                <button type="button"
                        class="close-icon"
                        data-hs-overlay="#{{ $id }}"
                        aria-label="Close">
                    <i data-lucide="x" class="size-5"></i>
                </button>
            </div>

            <div class="card-body overflow-y-auto">
                {{ $slot }}
            </div>

            @if($footer)
            <div class="border-t border-default-200 flex items-center justify-end gap-2 p-5">
                @if(isset($footerSlot))
                    {{ $footerSlot }}
                @else
                    <button type="button"
                            class="btn btn-light"
                            data-hs-overlay="#{{ $id }}">Close</button>
                    <button type="button" class="btn btn-primary">Save changes</button>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>
