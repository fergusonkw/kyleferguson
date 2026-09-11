@php
    $registry = app(\App\Services\Billing\ProviderAdapterRegistry::class);
@endphp
<div class="flex gap-1 justify-center">
    @can('sync', $provider)
        @if($registry->supportsResourceSync($provider->slug))
            <button type="button" class="btn btn-sm btn-light sync-provider" data-id="{{ $provider->id }}" aria-label="Sync resources" title="Sync resources">
                <i data-lucide="refresh-cw" class="size-4"></i>
            </button>
        @endif
        @if($registry->supportsBillingSync($provider->slug))
            <button type="button" class="btn btn-sm btn-light sync-provider-billing" data-id="{{ $provider->id }}" aria-label="Sync billing" title="Sync billing for this month">
                <i data-lucide="receipt" class="size-4"></i>
            </button>
        @endif
    @endcan
    @can('update', $provider)
        <button type="button" class="btn btn-sm btn-light edit-provider" data-id="{{ $provider->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan
    @can('delete', $provider)
        <button type="button" class="btn btn-sm btn-light delete-provider" data-id="{{ $provider->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
