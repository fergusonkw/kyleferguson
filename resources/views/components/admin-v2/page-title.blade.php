@props([
    'title' => null,
    'breadcrumbs' => [],
])

@if($title)
<div class="page-title-head flex items-center justify-between">
    <div class="grow">
        <h4 class="text-sm font-bold uppercase tracking-wide m-0">{{ $title }}</h4>
    </div>

    @if(!empty($breadcrumbs))
    <div>
        <ol class="flex items-center gap-1.5 text-sm text-default-400">
            <li>
                <a href="{{ route('admin.home') }}" class="hover:text-primary">
                    <i data-lucide="home" class="size-4 align-middle"></i>
                </a>
            </li>

            @foreach($breadcrumbs as $crumb)
                <li class="flex items-center gap-1.5">
                    <span>/</span>
                    @if(isset($crumb['active']) && $crumb['active'])
                        <span class="text-default-700">{{ $crumb['label'] }}</span>
                    @else
                        <a href="{{ $crumb['url'] ?? '#' }}" class="hover:text-primary">{{ $crumb['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
    @endif
</div>
@endif
