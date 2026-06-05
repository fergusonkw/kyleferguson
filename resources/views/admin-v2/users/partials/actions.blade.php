<div class="flex gap-1 justify-center">
    @can('update', $user)
        <button type="button" class="btn btn-sm btn-light edit-user" data-id="{{ $user->id }}" aria-label="Edit" title="Edit">
            <i data-lucide="pencil" class="size-4"></i>
        </button>
    @endcan

    @can('disable', $user)
        @if($user->is_disabled)
            <button type="button" class="btn btn-sm btn-warning disable-user" data-id="{{ $user->id }}" data-disabled="true" aria-label="Enable User" title="Enable User">
                <i data-lucide="circle-check" class="size-4"></i>
            </button>
        @else
            <button type="button" class="btn btn-sm btn-light disable-user" data-id="{{ $user->id }}" data-disabled="false" aria-label="Disable User" title="Disable User">
                <i data-lucide="ban" class="size-4"></i>
            </button>
        @endif
    @endcan

    @can('lock', $user)
        @if($user->is_locked)
            <button type="button" class="btn btn-sm btn-danger lock-user" data-id="{{ $user->id }}" data-locked="true" aria-label="Unlock User" title="Unlock User">
                <i data-lucide="lock-open" class="size-4"></i>
            </button>
        @else
            <button type="button" class="btn btn-sm btn-light lock-user" data-id="{{ $user->id }}" data-locked="false" aria-label="Lock User" title="Lock User">
                <i data-lucide="lock" class="size-4"></i>
            </button>
        @endif
    @endcan

    @can('delete', $user)
        <button type="button" class="btn btn-sm btn-light delete-user" data-id="{{ $user->id }}" aria-label="Delete" title="Delete">
            <i data-lucide="trash-2" class="size-4"></i>
        </button>
    @endcan
</div>
