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

            <x-admin-v2.form.select
                name="slug"
                label="Provider"
                :required="true"
                :options="\App\Enums\Billing\CostProviderSlug::options()"
            />
            <x-admin-v2.form.input name="display_name" label="Display Name" :required="true" placeholder="DigitalOcean (Production)" />
            <x-admin-v2.form.input name="token" type="password" label="API Token" placeholder="dop_v1_..." />
            <p class="text-xs text-default-400 -mt-2 mb-3" id="tokenHint">
                Read-only DigitalOcean Personal Access Token. Required when creating;
                leave blank on edit to keep the existing token.
            </p>

            {{-- Account-per-client providers bill one client outright. --}}
            <div id="clientLinkField" class="hidden">
                <x-admin-v2.form.select name="client_id" label="Client" :options="[]" placeholder="Select a client" />
                <p class="text-xs text-default-400 -mt-2 mb-3">
                    This account's whole charge is billed to this client. It attaches automatically to
                    their project when they have exactly one active project.
                </p>
            </div>

            {{-- SMTP2GO publishes usage but no cost, so the plan fee is entered here. --}}
            <div id="smtp2goFields" class="hidden">
                <x-admin-v2.form.select
                    name="region"
                    label="API Region"
                    :options="\App\Enums\Billing\Smtp2goRegion::options()"
                />
                <div class="grid grid-cols-2 gap-3">
                    <x-admin-v2.form.input name="monthly_fee" type="number" step="0.01" min="0" label="Monthly Fee" placeholder="15.00" />
                    <x-admin-v2.form.input name="fee_currency" label="Fee Currency" placeholder="USD" maxlength="3" value="USD" />
                </div>
                <p class="text-xs text-default-400 -mt-2 mb-3">
                    SMTP2GO has no cost API, so the plan fee is entered manually. Enter the all-in amount
                    they actually charge. Usage is pulled from their stats API for context.
                </p>
            </div>

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
    const clientsUrl = @json(route('admin.billing.cost-providers.available-clients'));
    const accountPerClientSlugs = @json(collect(\App\Enums\Billing\CostProviderSlug::cases())
        ->filter->isAccountPerClient()
        ->map->value
        ->values());
    let dataTable = null;
    let clientsLoaded = false;
    setTimeout(() => { dataTable = window.dataTable_costProvidersTable; }, 500);

    const slugSelect = document.querySelector('select[name="slug"]');
    const clientField = document.getElementById('clientLinkField');
    const clientSelect = document.querySelector('select[name="client_id"]');
    const smtp2goFields = document.getElementById('smtp2goFields');
    const tokenHint = document.getElementById('tokenHint');

    async function loadClients () {
        if (clientsLoaded) return;
        try {
            const r = await fetch(clientsUrl, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;
            clientSelect.innerHTML = '<option value="">Select a client</option>';
            data.clients.forEach((c) => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                clientSelect.appendChild(opt);
            });
            clientsLoaded = true;
        } catch { /* the field simply stays empty */ }
    }

    /** Show only the fields the selected provider actually needs. */
    async function applyProviderFields (slug) {
        const perClient = accountPerClientSlugs.includes(slug);
        const isSmtp2go = slug === 'smtp2go';

        clientField.classList.toggle('hidden', !perClient);
        clientSelect.required = perClient;
        if (perClient) await loadClients();

        smtp2goFields.classList.toggle('hidden', !isSmtp2go);
        smtp2goFields.querySelectorAll('input, select').forEach((el) => {
            el.required = isSmtp2go;
        });

        tokenHint.textContent = isSmtp2go
            ? 'SMTP2GO API key with stats permissions. Required when creating; leave blank on edit to keep the existing key.'
            : 'Read-only DigitalOcean Personal Access Token. Required when creating; leave blank on edit to keep the existing token.';
    }

    slugSelect?.addEventListener('change', (e) => applyProviderFields(e.target.value));

    function resetForm () {
        document.getElementById('providerForm').reset();
        document.querySelector('input[name="enabled"]').checked = true;
        document.getElementById('providerId').value = '';
    }

    document.getElementById('createProviderBtn')?.addEventListener('click', async () => {
        resetForm();
        document.getElementById('providerOffcanvasLabel').textContent = 'Add Cost Provider';
        document.getElementById('providerFormMethod').value = 'POST';
        document.querySelector('input[name="token"]').required = true;
        await applyProviderFields(slugSelect.value);
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
            slugSelect.value = p.slug;
            await applyProviderFields(p.slug);
            document.querySelector('input[name="display_name"]').value = p.display_name;
            document.querySelector('input[name="enabled"]').checked = p.enabled;
            document.querySelector('input[name="token"]').required = false;
            if (p.client_id) clientSelect.value = p.client_id;
            if (p.region) document.querySelector('select[name="region"]').value = p.region;
            if (p.monthly_fee) document.querySelector('input[name="monthly_fee"]').value = p.monthly_fee;
            if (p.fee_currency) document.querySelector('input[name="fee_currency"]').value = p.fee_currency;
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

    async function triggerSync (id, endpoint, failureMessage) {
        try {
            const r = await fetch(`${indexUrl}/${id}/${endpoint}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            const data = await r.json();
            if (data.success) { Alert.toast(data.message, 'success'); setTimeout(() => dataTable?.ajax.reload(), 1500); }
            else Alert.error(data.message || failureMessage);
        } catch { Alert.error(failureMessage); }
    }

    document.addEventListener('click', (e) => {
        const sync = e.target.closest('.sync-provider');
        if (sync) { triggerSync(sync.dataset.id, 'sync', 'Failed to trigger sync.'); return; }

        const syncBilling = e.target.closest('.sync-provider-billing');
        if (syncBilling) { triggerSync(syncBilling.dataset.id, 'sync-billing', 'Failed to trigger billing sync.'); }
    });
});
</script>
@endpush
