@extends('admin-v2.layouts.vertical', ['title' => 'Recurring Line Templates'])

@section('content')
    <x-admin-v2.page-title
        title="Recurring Line Templates"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Recurring Templates', 'active' => true]]"
    />

    <x-admin-v2.card title="Recurring Line Templates">
        <x-slot:headerActions>
            @can('create', \App\Models\Billing\RecurringLineTemplate::class)
                <button type="button" class="btn btn-primary btn-sm" id="createTemplateBtn">
                    <i data-lucide="plus" class="size-4 me-1"></i> Add Template
                </button>
            @endcan
        </x-slot:headerActions>

        <x-admin-v2.datatable
            id="templatesTable"
            :columns="[
                ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                ['title' => 'Label', 'data' => 'label'],
                ['title' => 'Client', 'data' => 'client'],
                ['title' => 'Project', 'data' => 'project'],
                ['title' => 'Amount', 'data' => 'amount', 'className' => 'text-end'],
                ['title' => 'Cadence', 'data' => 'cadence', 'className' => 'text-center'],
                ['title' => 'Active From', 'data' => 'active_from', 'className' => 'text-center'],
                ['title' => 'Active To', 'data' => 'active_to', 'className' => 'text-center'],
                ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '100px'],
            ]"
            ajax-url="{{ route('admin.billing.recurring-line-templates.data') }}"
            :server-side="true"
        />
    </x-admin-v2.card>

    <x-admin-v2.offcanvas canvasId="templateOffcanvas" title="Add Recurring Template" size="large">
        <form id="templateForm">
            @csrf
            <input type="hidden" id="templateId" name="template_id">
            <input type="hidden" id="templateFormMethod" value="POST">

            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.select
                    name="client_id"
                    label="Client"
                    :options="$clients->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
                    :placeholder="'— Select client —'"
                />
                <x-admin-v2.form.input name="project_id" type="number" label="Project ID (optional)" placeholder="Leave blank for client-level" />
            </div>
            <p class="text-xs text-default-400 -mt-2 mb-3">
                Set either client or project. Project-level templates narrow applicability to that project only.
            </p>

            <x-admin-v2.form.input name="label" label="Label" :required="true" placeholder="e.g. Monthly Support Retainer" />

            <div class="grid grid-cols-3 gap-3">
                <x-admin-v2.form.input name="amount" type="number" step="0.01" min="0" label="Amount" :required="true" />
                <x-admin-v2.form.input name="currency" label="Currency" :required="true" placeholder="CAD" maxlength="3" />
                <x-admin-v2.form.select name="cadence" label="Cadence" :required="true" :options="$cadenceOptions" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.input name="active_from" type="date" label="Active From" :required="true" />
                <x-admin-v2.form.input name="active_to" type="date" label="Active To (optional)" />
            </div>

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#templateOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveTemplateBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Template</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.recurring-line-templates.index'));
    let dataTable = null;
    setTimeout(() => { dataTable = window.dataTable_templatesTable; }, 500);

    function resetForm () { document.getElementById('templateForm').reset(); }

    function populate (t) {
        for (const k of ['client_id', 'project_id', 'label', 'amount', 'currency', 'cadence', 'active_from', 'active_to']) {
            const el = document.querySelector(`[name="${k}"]`);
            if (el) { el.value = t[k] ?? ''; }
        }
        document.getElementById('templateId').value = t.id;
    }

    document.getElementById('createTemplateBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('templateOffcanvasLabel').textContent = 'Add Recurring Template';
        document.getElementById('templateFormMethod').value = 'POST';
        HSOverlay.open('#templateOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-template');
        if (!editBtn) { return; }
        try {
            const r = await fetch(`${indexUrl}/${editBtn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) { return; }
            resetForm();
            populate(data.template);
            document.getElementById('templateOffcanvasLabel').textContent = 'Edit Recurring Template';
            document.getElementById('templateFormMethod').value = 'PUT';
            HSOverlay.open('#templateOffcanvas');
        } catch { Alert.error('Failed to load template.'); }
    });

    document.getElementById('templateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('templateFormMethod').value;
        const id = document.getElementById('templateId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') { formData.append('_method', 'PUT'); }
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveTemplateBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) { HSOverlay.close('#templateOffcanvas'); if (dataTable) { dataTable.ajax.reload(); } Alert.toast(data.message, 'success'); }
            else { const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.'); Alert.html(msg, 'Error'); }
        } catch { Alert.error('An error occurred.'); }
        finally { btn.disabled = false; t.textContent = orig; }
    });

    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.delete-template');
        if (!del) { return; }
        const ok = await Alert.confirmDelete('This will not affect invoices already generated.', 'Delete Template?');
        if (!ok) { return; }
        try {
            const r = await fetch(`${indexUrl}/${del.dataset.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) { dataTable.ajax.reload(); } Alert.toast(data.message, 'success'); }
            else { Alert.error(data.message || 'Failed to delete.'); }
        } catch { Alert.error('Failed to delete.'); }
    });
});
</script>
@endpush
