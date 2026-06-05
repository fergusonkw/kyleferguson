@extends('admin-v2.layouts.vertical', ['title' => 'Users'])

@section('content')
<x-admin-v2.page-title
    title="User Management"
    :breadcrumbs="[['label' => 'Users', 'active' => true]]"
/>

<x-admin-v2.card title="All Users">
    <x-slot:headerActions>
        @can('assignRoles', App\Models\User::class)
        <button type="button" class="btn btn-primary btn-sm" id="createUserBtn">
            <i data-lucide="plus" class="size-4 me-1"></i> Create User
        </button>
        @endcan
    </x-slot:headerActions>

    <x-admin-v2.datatable
        id="usersTable"
        :columns="[
            ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
            ['title' => 'Name', 'data' => 'name'],
            ['title' => 'Email', 'data' => 'email'],
            ['title' => 'Status', 'data' => 'status', 'className' => 'text-center', 'orderable' => false],
            ['title' => 'Roles', 'data' => 'roles', 'orderable' => false],
            ['title' => 'Created', 'data' => 'created_at', 'className' => 'text-center'],
            ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '200px'],
        ]"
        ajax-url="{{ route('admin.users.data') }}"
        :server-side="true"
    />
</x-admin-v2.card>

<x-admin-v2.offcanvas canvasId="userOffcanvas" title="Create User" size="large">
    <form id="userForm">
        @csrf
        <input type="hidden" id="userId" name="user_id">
        <input type="hidden" id="formMethod" value="POST">

        <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Basic Information</p>

        <x-admin-v2.form.input name="name" label="Full Name" :required="true" placeholder="Enter full name" />
        <x-admin-v2.form.input name="email" type="email" label="Email Address" :required="true" placeholder="Enter email address" />

        <details id="passwordDetails" open>
            <summary class="cursor-pointer text-sm font-medium flex items-center gap-2 mb-3 select-none">
                <i data-lucide="lock" class="size-4"></i>
                <span id="passwordSectionTitle">Password</span>
            </summary>
            <div class="pl-6 border-l-2 border-default-100 ml-1">
                <x-admin-v2.alert type="info" class="mb-3 hidden" id="passwordEditHint">
                    Leave blank to keep the current password unchanged.
                </x-admin-v2.alert>
                <x-admin-v2.form.input name="password" type="password" label="Password" placeholder="Enter password" id="password-input" />
                <x-admin-v2.form.input name="password_confirmation" type="password" label="Confirm Password" placeholder="Confirm password" id="password-confirmation-input" />
            </div>
        </details>

        <div id="rolesSection" class="mb-5">
            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Roles</p>
            <div id="rolesContainer" class="flex flex-col gap-2"></div>
        </div>

        <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
            <button type="button" class="btn btn-light" data-hs-overlay="#userOffcanvas">Cancel</button>
            <button type="submit" class="btn btn-primary" id="saveUserBtn">
                <i data-lucide="check" class="size-4 me-1"></i>
                <span class="btn-text">Save User</span>
            </button>
        </div>
    </form>
</x-admin-v2.offcanvas>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const usersUrl = @json(route('admin.users.index'));
    const rolesUrl = @json(route('admin.users.roles'));
    let dataTable = null;
    let availableRoles = [];

    setTimeout(() => { dataTable = window.dataTable_usersTable; }, 500);

    fetch(rolesUrl, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => { if (data.success) { availableRoles = data.data; renderRoleCheckboxes([]); } })
        .catch(() => {});

    function renderRoleCheckboxes(selectedIds) {
        const container = document.getElementById('rolesContainer');
        if (!container) return;
        container.innerHTML = availableRoles.map(role => `
            <label class="flex items-start gap-2">
                <input type="checkbox" class="form-checkbox mt-0.5" name="roles[]" value="${role.id}" ${selectedIds.includes(role.id) ? 'checked' : ''}>
                <span>
                    <span class="text-sm font-medium">${role.name}</span>
                    ${role.description ? `<span class="block text-xs text-default-400">${role.description}</span>` : ''}
                </span>
            </label>
        `).join('');
    }

    document.getElementById('createUserBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('userOffcanvasLabel').textContent = 'Create User';
        document.getElementById('formMethod').value = 'POST';
        document.getElementById('userId').value = '';
        const passwordInput = document.getElementById('password-input');
        const passwordConfInput = document.getElementById('password-confirmation-input');
        if (passwordInput) passwordInput.required = true;
        if (passwordConfInput) passwordConfInput.required = true;
        document.getElementById('passwordSectionTitle').textContent = 'Password';
        document.getElementById('passwordEditHint')?.classList.add('hidden');
        document.getElementById('passwordDetails')?.setAttribute('open', '');
        HSOverlay.open('#userOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-user');
        if (!editBtn) return;
        const id = editBtn.dataset.id;
        try {
            const r = await fetch(`${usersUrl}/${id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) {
                resetForm();
                populateForm(data.user);
                document.getElementById('userOffcanvasLabel').textContent = 'Edit User';
                document.getElementById('formMethod').value = 'PUT';
                const passwordInput = document.getElementById('password-input');
                const passwordConfInput = document.getElementById('password-confirmation-input');
                if (passwordInput) passwordInput.required = false;
                if (passwordConfInput) passwordConfInput.required = false;
                document.getElementById('passwordSectionTitle').textContent = 'Change Password (Optional)';
                document.getElementById('passwordEditHint')?.classList.remove('hidden');
                document.getElementById('passwordDetails')?.removeAttribute('open');
                HSOverlay.open('#userOffcanvas');
            }
        } catch { Alert.error('Failed to load user data.'); }
    });

    function populateForm(user) {
        document.getElementById('userId').value = user.id;
        document.querySelector('input[name="name"]').value = user.name ?? '';
        document.querySelector('input[name="email"]').value = user.email ?? '';
        renderRoleCheckboxes((user.role_ids || []).map(Number));
    }

    function resetForm() {
        document.getElementById('userForm').reset();
        renderRoleCheckboxes([]);
    }

    document.getElementById('userForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const method = document.getElementById('formMethod').value;
        const userId = document.getElementById('userId').value;

        const formData = new FormData(form);
        if (method === 'PUT') {
            formData.append('_method', 'PUT');
            const password = formData.get('password');
            const passwordConf = formData.get('password_confirmation');
            if (!password && !passwordConf) {
                formData.delete('password');
                formData.delete('password_confirmation');
            }
        }

        const url = userId ? `${usersUrl}/${userId}` : usersUrl;
        const submitBtn = document.getElementById('saveUserBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const original = btnText.textContent;
        submitBtn.disabled = true; btnText.textContent = 'Saving...';

        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#userOffcanvas');
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
        const deleteBtn = e.target.closest('.delete-user');
        if (!deleteBtn) return;
        const id = deleteBtn.dataset.id;
        const confirmed = await Alert.confirmDelete("You won't be able to revert this!", 'Delete User?');
        if (!confirmed) return;
        try {
            const r = await fetch(`${usersUrl}/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Failed to delete.');
        } catch { Alert.error('Failed to delete. Please try again.'); }
    });

    document.addEventListener('click', async (e) => {
        const disableBtn = e.target.closest('.disable-user');
        if (!disableBtn) return;
        const id = disableBtn.dataset.id;
        const isDisabled = disableBtn.dataset.disabled === '1' || disableBtn.dataset.disabled === 'true';
        const action = isDisabled ? 'Enable' : 'Disable';
        const confirmed = await Alert.confirm(`Are you sure you want to ${action.toLowerCase()} this user?`, `${action} User?`);
        if (!confirmed) return;
        try {
            const r = await fetch(`${usersUrl}/${id}/disable`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || `Failed to ${action.toLowerCase()} user.`);
        } catch { Alert.error('An error occurred. Please try again.'); }
    });

    document.addEventListener('click', async (e) => {
        const lockBtn = e.target.closest('.lock-user');
        if (!lockBtn) return;
        const id = lockBtn.dataset.id;
        const isLocked = lockBtn.dataset.locked === '1' || lockBtn.dataset.locked === 'true';
        const action = isLocked ? 'Unlock' : 'Lock';
        const confirmed = await Alert.confirm(`Are you sure you want to ${action.toLowerCase()} this user?`, `${action} User?`);
        if (!confirmed) return;
        try {
            const r = await fetch(`${usersUrl}/${id}/lock`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || `Failed to ${action.toLowerCase()} user.`);
        } catch { Alert.error('An error occurred. Please try again.'); }
    });
});
</script>
@endpush
