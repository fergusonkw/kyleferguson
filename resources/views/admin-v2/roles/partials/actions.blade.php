<div class="flex gap-1 justify-center">
    @can('update', $role)
        <button type="button" class="btn btn-sm btn-light edit-role" data-id="{{ $role->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan

    @can('delete', $role)
        <button type="button" class="btn btn-sm btn-light delete-role" data-id="{{ $role->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
