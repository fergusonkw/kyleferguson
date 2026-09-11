<div class="flex gap-1 justify-center">
    @can('update', $entity)
        <button type="button" class="btn btn-sm btn-light edit-entity" data-id="{{ $entity->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan

    @can('delete', $entity)
        <button type="button" class="btn btn-sm btn-light delete-entity" data-id="{{ $entity->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
