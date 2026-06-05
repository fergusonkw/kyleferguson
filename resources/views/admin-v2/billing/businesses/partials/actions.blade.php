<div class="flex gap-1 justify-center">
    @can('update', $business)
        <button type="button" class="btn btn-sm btn-light edit-business" data-id="{{ $business->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan

    @can('delete', $business)
        <button type="button" class="btn btn-sm btn-light delete-business" data-id="{{ $business->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
