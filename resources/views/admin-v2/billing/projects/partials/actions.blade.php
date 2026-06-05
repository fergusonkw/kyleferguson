<div class="flex gap-1 justify-center">
    @can('update', $project)
        <button type="button" class="btn btn-sm btn-light edit-project" data-id="{{ $project->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan
    @can('delete', $project)
        <button type="button" class="btn btn-sm btn-light delete-project" data-id="{{ $project->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
