@extends('admin-v2.layouts.vertical', ['title' => 'Cost Providers'])

@section('content')
    <x-admin-v2.page-title
        title="Cost Providers"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Cost Providers', 'active' => true]]"
    />

    @isset($currentBusiness)
        <x-admin-v2.card title="{{ $currentBusiness->name }} — Providers">
            <x-slot:headerActions>
                @can('create', \App\Models\Billing\CostProvider::class)
                    <button type="button" class="btn btn-primary btn-sm" id="createProviderBtn">
                        <i data-lucide="plus" class="size-4 me-1"></i> Add Provider
                    </button>
                @endcan
            </x-slot:headerActions>

            <x-admin-v2.datatable
                id="costProvidersTable"
                :columns="[
                    ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                    ['title' => 'Name', 'data' => 'display_name'],
                    ['title' => 'Provider', 'data' => 'slug'],
                    ['title' => 'Status', 'data' => 'enabled', 'className' => 'text-center', 'orderable' => false],
                    ['title' => 'Last Sync', 'data' => 'last_sync_status', 'className' => 'text-center', 'orderable' => false],
                    ['title' => 'When', 'data' => 'last_synced_at', 'className' => 'text-center', 'orderable' => false],
                    ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '150px'],
                ]"
                ajax-url="{{ route('admin.billing.cost-providers.data') }}"
                :server-side="true"
            />
        </x-admin-v2.card>
    @else
        <x-admin-v2.alert type="warning" message="Create a business first to manage cost providers." />
    @endisset

    <x-admin-v2.offcanvas canvasId="providerOffcanvas" title="Add Cost Provider" size="medium">
        <form id="providerForm">
            @csrf
            <input type="hidden" id="providerId" name="provider_id">
            <input type="hidden" id="providerFormMethod" value="POST">
            <input type="hidden" name="business_id" value="{{ $currentBusiness?->id }}">

            <x-admin-v2.form.select name="slug" label="Provider" :required="true" :options="['digitalocean' => 'DigitalOcean']" />
            <x-admin-v2.form.input name="display_name" label="Display Name" :required="true" placeholder="DigitalOcean (Production)" />
            <x-admin-v2.form.input name="token" type="password" label="API Token" placeholder="dop_v1_..." />
            <p class="text-xs text-default-400 -mt-2 mb-3" id="tokenHint">
                Read-only DigitalOcean Personal Access Token. Required when creating;
                leave blank on edit to keep the existing token.
            </p>

            <label class="flex items-center gap-2 mb-4">
                <input type="checkbox" name="enabled" value="1" class="form-checkbox" checked>
                <span class="text-sm">Enabled (eligible for scheduled sync)</span>
            </label>

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#providerOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveProviderBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Provider</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.cost-providers.index'));
    let dataTable = null;
    setTimeout(() => { dataTable = window.dataTable_costProvidersTable; }, 500);

    function resetForm () {
        document.getElementById('providerForm').reset();
        document.querySelector('input[name="enabled"]').checked = true;
    }

    document.getElementById('createProviderBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('providerOffcanvasLabel').textContent = 'Add Cost Provider';
        document.getElementById('providerFormMethod').value = 'POST';
        document.querySelector('input[name="token"]').required = true;
        HSOverlay.open('#providerOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-provider');
        if (!editBtn) return;
        const id = editBtn.dataset.id;
        try {
            const r = await fetch(`${indexUrl}/${id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;
            resetForm();
            const p = data.provider;
            document.getElementById('providerId').value = p.id;
            document.querySelector('select[name="slug"]').value = p.slug;
            document.querySelector('input[name="display_name"]').value = p.display_name;
            document.querySelector('input[name="enabled"]').checked = p.enabled;
            document.querySelector('input[name="token"]').required = false;
            document.getElementById('providerOffcanvasLabel').textContent = 'Edit Cost Provider';
            document.getElementById('providerFormMethod').value = 'PUT';
            HSOverlay.open('#providerOffcanvas');
        } catch { Alert.error('Failed to load provider.'); }
    });

    document.getElementById('providerForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('providerFormMethod').value;
        const id = document.getElementById('providerId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') {
            formData.append('_method', 'PUT');
            if (!formData.get('token')) formData.delete('token');
        }
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveProviderBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#providerOffcanvas');
                if (dataTable) dataTable.ajax.reload();
                Alert.toast(data.message, data.token_valid === false ? 'warning' : 'success');
            } else {
                const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.');
                Alert.html(msg, 'Error');
            }
        } catch { Alert.error('An error occurred.'); }
        finally { btn.disabled = false; t.textContent = orig; }
    });

    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.delete-provider');
        if (!del) return;
        const id = del.dataset.id;
        const ok = await Alert.confirmDelete('All resource history for this provider will be removed.', 'Delete Provider?');
        if (!ok) return;
        try {
            const r = await fetch(`${indexUrl}/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Failed to delete.');
        } catch { Alert.error('Failed to delete.'); }
    });

    document.addEventListener('click', async (e) => {
        const sync = e.target.closest('.sync-provider');
        if (!sync) return;
        const id = sync.dataset.id;
        try {
            const r = await fetch(`${indexUrl}/${id}/sync`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { Alert.toast(data.message, 'success'); setTimeout(() => dataTable?.ajax.reload(), 1500); }
            else Alert.error(data.message || 'Failed to trigger sync.');
        } catch { Alert.error('Failed to trigger sync.'); }
    });
});
</script>
@endpush
