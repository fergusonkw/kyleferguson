@extends('admin-v2.layouts.vertical', ['title' => 'Legal Entities'])

@section('content')
    <x-admin-v2.page-title
        title="Legal Entities"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Legal Entities', 'active' => true]]"
    />

    <x-admin-v2.card title="All Legal Entities"
        subtitle="The person or corporation behind each business. GST/HST registration and the $30,000 small-supplier threshold belong here, not to a trade name.">
        <x-slot:headerActions>
            @can('create', \App\Models\Billing\LegalEntity::class)
                <button type="button" class="btn btn-primary btn-sm" id="createEntityBtn">
                    <i data-lucide="plus" class="size-4 me-1"></i> Add Legal Entity
                </button>
            @endcan
        </x-slot:headerActions>

        <x-admin-v2.datatable
            id="legalEntitiesTable"
            :columns="[
                ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                ['title' => 'Name', 'data' => 'name'],
                ['title' => 'Type', 'data' => 'entity_type', 'orderable' => false],
                ['title' => 'Businesses', 'data' => 'businesses', 'orderable' => false],
                ['title' => 'Associated With', 'data' => 'associates', 'orderable' => false],
                ['title' => 'GST/HST', 'data' => 'tax_registered', 'orderable' => false, 'className' => 'text-center'],
                ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '120px'],
            ]"
            ajax-url="{{ route('admin.billing.legal-entities.data') }}"
            :server-side="true"
        />
    </x-admin-v2.card>

    <x-admin-v2.offcanvas canvasId="entityOffcanvas" title="Add Legal Entity" size="medium">
        <form id="entityForm">
            @csrf
            <input type="hidden" id="entityId">
            <input type="hidden" id="entityFormMethod" value="POST">

            <x-admin-v2.form.input name="name" label="Legal Name" :required="true" placeholder="e.g. Kyle Ferguson, or Tracker Pull Inc." />
            <x-admin-v2.form.select name="entity_type" label="Entity Type" :options="$entityTypes" :placeholder="null" :required="true" />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">GST/HST</p>
            <x-admin-v2.form.input name="tax_registered_from" type="date" label="Registered From (optional)" />
            <p class="text-xs text-default-400 -mt-3 mb-4">
                Covers every business under this entity. Leave blank while it is a small supplier.
            </p>
            <x-admin-v2.form.input name="threshold_warning_percent" type="number" min="1" max="99"
                label="Warn At (% of $30,000)" :required="true" />
            <p class="text-xs text-default-400 -mt-3 mb-4">
                The dashboard turns amber and an email goes out once taxable supplies reach this share of the threshold.
            </p>

            <div class="mb-4">
                <label class="form-label">Associated Entities</label>
                <div id="associatesContainer" class="flex flex-col gap-2">
                    <p class="text-sm text-default-400" id="noAssociatesHint">No other legal entities yet.</p>
                </div>
                <p class="text-xs text-default-400 mt-2">
                    Entities whose sales are added to this one's for the $30,000 test — for example a corporation
                    you control. Separate registration does not make them separate for the threshold. Confirm with
                    your accountant which entities are associated.
                </p>
            </div>

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#entityOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveEntityBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Legal Entity</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.legal-entities.index'));
    const optionsUrl = @json(route('admin.billing.legal-entities.options'));
    const container = document.getElementById('associatesContainer');
    let dataTable = null;
    setTimeout(() => { dataTable = window.dataTable_legalEntitiesTable; }, 500);

    /** Every other entity as a checkbox; an entity cannot be its own associate. */
    async function renderAssociates (currentId, selectedIds) {
        container.querySelectorAll('label').forEach((el) => el.remove());
        const hint = document.getElementById('noAssociatesHint');
        try {
            const r = await fetch(optionsUrl, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            const others = (data.entities || []).filter((e) => String(e.id) !== String(currentId));
            hint.classList.toggle('hidden', others.length > 0);
            others.forEach((e) => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-checkbox';
                input.name = 'associate_ids[]';
                input.value = e.id;
                input.checked = selectedIds.map(String).includes(String(e.id));
                const span = document.createElement('span');
                span.className = 'text-sm';
                span.textContent = e.name;
                label.append(input, span);
                container.appendChild(label);
            });
        } catch { hint.classList.remove('hidden'); }
    }

    function resetForm () {
        document.getElementById('entityForm').reset();
        document.getElementById('entityId').value = '';
    }

    document.getElementById('createEntityBtn')?.addEventListener('click', async () => {
        resetForm();
        document.getElementById('entityOffcanvasLabel').textContent = 'Add Legal Entity';
        document.getElementById('entityFormMethod').value = 'POST';
        document.querySelector('input[name="threshold_warning_percent"]').value = '80';
        await renderAssociates(null, []);
        HSOverlay.open('#entityOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-entity');
        if (!editBtn) return;
        try {
            const r = await fetch(`${indexUrl}/${editBtn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;
            resetForm();
            const entity = data.entity;
            document.getElementById('entityId').value = entity.id;
            for (const k of ['name', 'entity_type', 'tax_registered_from', 'threshold_warning_percent']) {
                const el = document.querySelector(`#entityForm [name="${k}"]`);
                if (el) el.value = entity[k] ?? '';
            }
            await renderAssociates(entity.id, entity.associate_ids || []);
            document.getElementById('entityOffcanvasLabel').textContent = 'Edit Legal Entity';
            document.getElementById('entityFormMethod').value = 'PUT';
            HSOverlay.open('#entityOffcanvas');
        } catch { Alert.error('Failed to load legal entity.'); }
    });

    document.getElementById('entityForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('entityFormMethod').value;
        const id = document.getElementById('entityId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') formData.append('_method', 'PUT');
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveEntityBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#entityOffcanvas');
                if (dataTable) dataTable.ajax.reload();
                Alert.toast(data.message, 'success');
            } else {
                const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.');
                Alert.html(msg, 'Error');
            }
        } catch { Alert.error('An error occurred.'); }
        finally { btn.disabled = false; t.textContent = orig; }
    });

    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.delete-entity');
        if (!del) return;
        const ok = await Alert.confirmDelete('This action cannot be undone.', 'Delete Legal Entity?');
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
