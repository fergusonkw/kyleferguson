<div class="flex gap-1 justify-center">
    @can('update', $template)
        <button type="button" class="btn btn-sm btn-light edit-template" data-id="{{ $template->id }}"
                aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan
    @can('delete', $template)
        <button type="button" class="btn btn-sm btn-light delete-template" data-id="{{ $template->id }}"
                aria-label="Remove" title="Remove">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
