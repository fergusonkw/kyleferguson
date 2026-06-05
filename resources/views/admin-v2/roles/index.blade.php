@extends('admin-v2.layouts.vertical', ['title' => 'Roles & Permissions'])

@section('content')
<x-admin-v2.page-title
    title="Roles & Permissions"
    :breadcrumbs="[['label' => 'Roles', 'active' => true]]"
/>

<x-admin-v2.card title="All Roles">
    <x-slot:headerActions>
        @can('create', App\Models\Role::class)
        <button type="button" class="btn btn-primary btn-sm" id="createRoleBtn">
            <i data-lucide="plus" class="size-4 me-1"></i> Create Role
        </button>
        @endcan
    </x-slot:headerActions>

    <x-admin-v2.datatable
        id="rolesTable"
        :columns="[
            ['title' => 'Name', 'data' => 'name'],
            ['title' => 'Slug', 'data' => 'slug'],
            ['title' => 'Level', 'data' => 'level', 'className' => 'text-center'],
            ['title' => 'Permissions', 'data' => 'permissions_count', 'className' => 'text-center', 'orderable' => false],
            ['title' => 'Users', 'data' => 'users_count', 'className' => 'text-center', 'orderable' => false],
            ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '120px'],
        ]"
        ajax-url="{{ route('admin.roles.data') }}"
        :server-side="true"
    />
</x-admin-v2.card>

<x-admin-v2.offcanvas canvasId="roleOffcanvas" title="Create Role" size="xlarge">
    <form id="roleForm">
        @csrf
        <input type="hidden" id="roleId" name="role_id">
        <input type="hidden" id="formMethod" value="POST">

        <x-admin-v2.alert type="info" class="mb-4 hidden" id="coreRoleHint">
            This is a built-in role. You can adjust its permissions, but it cannot be deleted and its slug is fixed.
        </x-admin-v2.alert>

        <x-admin-v2.form.input name="name" label="Role Name" :required="true" placeholder="e.g. Editor" />
        <x-admin-v2.form.textarea name="description" label="Description" placeholder="What can this role do?" />
        <x-admin-v2.form.input name="level" type="number" label="Level" :required="true" placeholder="0–100" value="10" />
        <p class="text-xs text-default-400 -mt-2 mb-4">Higher levels denote more privilege. For reference: User = 10, Administrator = 50, Super Administrator = 100.</p>

        <hr class="border-default-200 my-4">
        <div class="flex items-center justify-between mb-3">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider">Permissions</p>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="form-checkbox" id="selectAllPermissions"> Select all
            </label>
        </div>
        <div id="permissionsContainer" class="flex flex-col gap-4"></div>

        <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
            <button type="button" class="btn btn-light" data-hs-overlay="#roleOffcanvas">Cancel</button>
            <button type="submit" class="btn btn-primary" id="saveRoleBtn">
                <i data-lucide="check" class="size-4 me-1"></i>
                <span class="btn-text">Save Role</span>
            </button>
        </div>
    </form>
</x-admin-v2.offcanvas>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const rolesUrl = @json(route('admin.roles.index'));
    const permissionsUrl = @json(route('admin.roles.permissions'));
    let dataTable = null;
    let groupedPermissions = {};

    setTimeout(() => { dataTable = window.dataTable_rolesTable; }, 500);

    fetch(permissionsUrl, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => { if (data.success) { groupedPermissions = data.data; renderPermissions([]); } })
        .catch(() => {});

    function renderPermissions(selectedIds) {
        const container = document.getElementById('permissionsContainer');
        if (!container) return;
        const ids = selectedIds.map(Number);
        container.innerHTML = Object.entries(groupedPermissions).map(([category, perms]) => `
            <div>
                <p class="text-sm font-medium mb-2">${category}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    ${perms.map(p => `
                        <label class="flex items-center gap-2">
                            <input type="checkbox" class="form-checkbox permission-checkbox" name="permissions[]" value="${p.id}" ${ids.includes(p.id) ? 'checked' : ''}>
                            <span class="text-sm">${p.name}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `).join('');
        syncSelectAll();
    }

    function syncSelectAll() {
        const boxes = Array.from(document.querySelectorAll('.permission-checkbox'));
        const selectAll = document.getElementById('selectAllPermissions');
        if (selectAll) selectAll.checked = boxes.length > 0 && boxes.every(b => b.checked);
    }

    document.getElementById('selectAllPermissions')?.addEventListener('change', function() {
        document.querySelectorAll('.permission-checkbox').forEach(b => { b.checked = this.checked; });
    });
    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('permission-checkbox')) syncSelectAll();
    });

    document.getElementById('createRoleBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('roleOffcanvasLabel').textContent = 'Create Role';
        document.getElementById('formMethod').value = 'POST';
        document.getElementById('roleId').value = '';
        document.getElementById('coreRoleHint')?.classList.add('hidden');
        HSOverlay.open('#roleOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-role');
        if (!editBtn) return;
        try {
            const r = await fetch(`${rolesUrl}/${editBtn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) {
                resetForm();
                populateForm(data.role);
                document.getElementById('roleOffcanvasLabel').textContent = 'Edit Role';
                document.getElementById('formMethod').value = 'PUT';
                HSOverlay.open('#roleOffcanvas');
            }
        } catch { Alert.error('Failed to load role.'); }
    });

    function populateForm(role) {
        document.getElementById('roleId').value = role.id;
        document.querySelector('input[name="name"]').value = role.name ?? '';
        document.querySelector('textarea[name="description"]').value = role.description ?? '';
        document.querySelector('input[name="level"]').value = role.level ?? 0;
        document.getElementById('coreRoleHint')?.classList.toggle('hidden', !role.is_core);
        renderPermissions(role.permission_ids || []);
    }

    function resetForm() {
        document.getElementById('roleForm').reset();
        document.querySelector('input[name="level"]').value = 10;
        renderPermissions([]);
    }

    document.getElementById('roleForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const method = document.getElementById('formMethod').value;
        const roleId = document.getElementById('roleId').value;

        const formData = new FormData(form);
        if (method === 'PUT') formData.append('_method', 'PUT');

        const url = roleId ? `${rolesUrl}/${roleId}` : rolesUrl;
        const submitBtn = document.getElementById('saveRoleBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const original = btnText.textContent;
        submitBtn.disabled = true; btnText.textContent = 'Saving...';

        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#roleOffcanvas');
                if (dataTable) dataTable.ajax.reload();
                Alert.toast(data.message, 'success');
            } else {
                const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.');
                Alert.html(msg, 'Error');
            }
        } catch { Alert.error('An error occurred. Please try again.'); }
        finally { submitBtn.disabled = false; btnText.textContent = original; }
    });

    document.addEventListener('click', async (e) => {
        const deleteBtn = e.target.closest('.delete-role');
        if (!deleteBtn) return;
        const confirmed = await Alert.confirmDelete("You won't be able to revert this!", 'Delete Role?');
        if (!confirmed) return;
        try {
            const r = await fetch(`${rolesUrl}/${deleteBtn.dataset.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Failed to delete.');
        } catch { Alert.error('Failed to delete. Please try again.'); }
    });
});
</script>
@endpush
