<div class="flex justify-center gap-1">
    @can('update', $template)
        <button type="button" class="btn btn-ghost btn-sm edit-template" data-id="{{ $template->id }}" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan
    @can('delete', $template)
        <button type="button" class="btn btn-ghost btn-sm delete-template text-danger" data-id="{{ $template->id }}" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
