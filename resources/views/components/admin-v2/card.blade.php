@props([
    'title' => null,
    'subtitle' => null,
    'collapsible' => false,
    'headerActions' => null,
    'bodyClass' => '',
    'noPadding' => false,
])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if($title || $collapsible || $headerActions)
    <div class="card-header flex items-center justify-between">
        <div class="grow">
            @if($title)
                <h4 class="card-title">{{ $title }}</h4>
            @endif
            @if($subtitle)
                <p class="text-default-400 text-sm mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($headerActions)
                {{ $headerActions }}
            @endif
            @if($collapsible)
                <button type="button" class="card-collapse-toggle">
                    <i data-lucide="chevron-up" class="size-5 text-default-400"></i>
                </button>
            @endif
        </div>
    </div>
    @endif

    <div class="card-body {{ $noPadding ? 'p-0' : '' }} {{ $bodyClass }}">
        {{ $slot }}
    </div>
</div>
