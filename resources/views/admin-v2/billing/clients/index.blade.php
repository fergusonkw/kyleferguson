@extends('admin-v2.layouts.vertical', ['title' => 'Clients'])

@section('content')
    <x-admin-v2.page-title
        title="Clients"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Clients', 'active' => true]]"
    />

    @isset($currentBusiness)
        <x-admin-v2.card title="{{ $currentBusiness->name }} — Clients">
            <x-slot:headerActions>
                @can('create', \App\Models\Billing\Client::class)
                    <button type="button" class="btn btn-primary btn-sm" id="createClientBtn">
                        <i data-lucide="plus" class="size-4 me-1"></i> Add Client
                    </button>
                @endcan
            </x-slot:headerActions>

            <x-admin-v2.datatable
                id="clientsTable"
                :columns="[
                    ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                    ['title' => 'Name', 'data' => 'name'],
                    ['title' => 'Contact', 'data' => 'contact_email'],
                    ['title' => 'Currency', 'data' => 'billing_currency', 'className' => 'text-center', 'width' => '80px'],
                    ['title' => 'Projects', 'data' => 'projects_count', 'className' => 'text-center', 'width' => '80px'],
                    ['title' => 'Status', 'data' => 'status', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Markup', 'data' => 'markup', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '120px'],
                ]"
                ajax-url="{{ route('admin.billing.clients.data') }}"
                :server-side="true"
            />
        </x-admin-v2.card>
    @else
        <x-admin-v2.alert type="warning" message="Create a business first to manage clients." />
    @endisset

    <x-admin-v2.offcanvas canvasId="clientOffcanvas" title="Add Client" size="large">
        <form id="clientForm">
            @csrf
            <input type="hidden" id="clientId" name="client_id">
            <input type="hidden" id="clientFormMethod" value="POST">
            <input type="hidden" name="business_id" value="{{ $currentBusiness?->id }}">

            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Identity</p>
            <x-admin-v2.form.input name="name" label="Client Name" :required="true" />
            <x-admin-v2.form.input name="contact_name" label="Contact Person" />
            <x-admin-v2.form.input name="contact_email" type="email" label="Contact Email" :required="true" />
            <x-admin-v2.form.textarea name="billing_address" label="Billing Address" rows="2" />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Billing</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.select name="billing_currency" label="Invoice Currency" :required="true"
                    :options="collect($currentBusiness?->supported_currencies ?? ['CAD'])->mapWithKeys(fn($c) => [$c => $c])->all()" />
                <x-admin-v2.form.select name="status" label="Status" :required="true" :options="$statusOptions" />
            </div>

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Default Markup (projects inherit unless overridden)</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.select name="default_markup_type" label="Markup Type" :required="true" :options="$markupOptions" />
                <x-admin-v2.form.input name="default_markup_value" type="number" step="0.0001" min="0" label="Markup Value" :required="true" placeholder="0" />
            </div>
            <p class="text-xs text-default-400 -mt-2 mb-3">
                Percent → %; Fixed/Hybrid → CAD; Pass-through → leave 0.
            </p>

            <hr class="border-default-200 my-4">
            <x-admin-v2.form.textarea name="notes" label="Notes" rows="2" />

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#clientOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveClientBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Client</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.clients.index'));
    let dataTable = null;
    setTimeout(() => { dataTable = window.dataTable_clientsTable; }, 500);

    function resetForm () { document.getElementById('clientForm').reset(); }

    function populate (c) {
        for (const k of ['name','contact_name','contact_email','billing_address','billing_currency',
            'status','default_markup_type','default_markup_value','notes']) {
            const el = document.querySelector(`[name="${k}"]`);
            if (el) el.value = c[k] ?? '';
        }
        document.getElementById('clientId').value = c.id;
    }

    document.getElementById('createClientBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('clientOffcanvasLabel').textContent = 'Add Client';
        document.getElementById('clientFormMethod').value = 'POST';
        document.querySelector('select[name="status"]').value = 'active';
        document.querySelector('select[name="default_markup_type"]').value = 'passthrough';
        document.querySelector('input[name="default_markup_value"]').value = '0';
        HSOverlay.open('#clientOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-client');
        if (!editBtn) return;
        try {
            const r = await fetch(`${indexUrl}/${editBtn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;
            resetForm();
            populate(data.client);
            document.getElementById('clientOffcanvasLabel').textContent = 'Edit Client';
            document.getElementById('clientFormMethod').value = 'PUT';
            HSOverlay.open('#clientOffcanvas');
        } catch { Alert.error('Failed to load client.'); }
    });

    document.getElementById('clientForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('clientFormMethod').value;
        const id = document.getElementById('clientId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') formData.append('_method', 'PUT');
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveClientBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) { HSOverlay.close('#clientOffcanvas'); if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else { const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.'); Alert.html(msg, 'Error'); }
        } catch { Alert.error('An error occurred.'); }
        finally { btn.disabled = false; t.textContent = orig; }
    });

    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.delete-client');
        if (!del) return;
        const ok = await Alert.confirmDelete('All historical attribution will remain, but the client record is removed.', 'Delete Client?');
        if (!ok) return;
        try {
            const r = await fetch(`${indexUrl}/${del.dataset.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Failed to delete.');
        } catch { Alert.error('Failed to delete.'); }
    });
});
</script>
@endpush
