@extends('admin-v2.layouts.vertical', ['title' => 'Projects'])

@section('content')
    <x-admin-v2.page-title
        title="Projects"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Projects', 'active' => true]]"
    />

    @isset($currentBusiness)
        <x-admin-v2.card title="{{ $currentBusiness->name }} — Projects">
            <x-slot:headerActions>
                <a href="{{ route('admin.billing.projects.unattributed') }}" class="btn btn-light btn-sm">
                    <i data-lucide="alert-triangle" class="size-4 me-1"></i> Unattributed
                </a>
                @can('create', \App\Models\Billing\Project::class)
                    <button type="button" class="btn btn-primary btn-sm" id="createProjectBtn">
                        <i data-lucide="plus" class="size-4 me-1"></i> Add Project
                    </button>
                @endcan
            </x-slot:headerActions>

            <x-admin-v2.datatable
                id="projectsTable"
                :columns="[
                    ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                    ['title' => 'Name', 'data' => 'name'],
                    ['title' => 'Client', 'data' => 'client'],
                    ['title' => 'DO Link', 'data' => 'do_linked', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Status', 'data' => 'status', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Markup', 'data' => 'markup', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '120px'],
                ]"
                ajax-url="{{ route('admin.billing.projects.data') }}"
                :server-side="true"
            />
        </x-admin-v2.card>
    @else
        <x-admin-v2.alert type="warning" message="Create a business first to manage projects." />
    @endisset

    <x-admin-v2.offcanvas canvasId="projectOffcanvas" title="Add Project" size="large">
        <form id="projectForm">
            @csrf
            <input type="hidden" id="projectId" name="project_id">
            <input type="hidden" id="projectFormMethod" value="POST">

            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Identity</p>
            <div class="mb-4">
                <label class="text-sm font-medium mb-1.5 block">Client <span class="text-danger">*</span></label>
                <select name="client_id" id="clientSelect" class="form-select" required></select>
            </div>
            <x-admin-v2.form.input name="name" label="Project Name" :required="true" />
            <x-admin-v2.form.select name="status" label="Status" :required="true" :options="$statusOptions" />

            <div id="terminatedAtWrap" class="hidden">
                <x-admin-v2.form.input name="terminated_at" type="date" label="Terminated On" />
                <p class="text-xs text-default-400 -mt-3 mb-4">
                    Standing charges on this project stop from the first period beginning after this
                    date, so a project ended mid-month is still billed for the month it ran. Left blank,
                    today's date is used.
                </p>
            </div>

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">DigitalOcean Link</p>
            <div class="mb-4">
                <label class="text-sm font-medium mb-1.5 block">Linked DO Project</label>
                <select name="do_project_uuid" id="doProjectSelect" class="form-select">
                    <option value="">— Not linked —</option>
                </select>
                <p class="text-xs text-default-400 mt-1.5">
                    Populated from previously synced DigitalOcean projects.
                    Run a manual sync from the Cost Providers page if a project you expect is missing.
                </p>
            </div>

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Markup Override (leave blank to inherit from client)</p>
            <x-admin-v2.form.select name="markup_type" label="Markup Type" :options="array_merge(['' => '— Inherit —'], $markupOptions)" />
            <div class="grid grid-cols-2 gap-3">
                <div data-markup-field="percent">
                    <x-admin-v2.form.input name="markup_value" type="number" step="0.0001" min="0"
                                           label="Markup %" placeholder="15" />
                </div>
                <div data-markup-field="fee">
                    <x-admin-v2.form.input name="markup_fee" type="number" step="0.01" min="0"
                                           label="Fixed Fee" placeholder="0.00" />
                </div>
            </div>
            <p class="text-xs text-default-400 -mt-2 mb-3" data-markup-hint>
                Markup applies to this project's hosting costs only, after conversion to the client's
                billing currency.
            </p>

            <hr class="border-default-200 my-4">
            <x-admin-v2.form.textarea name="notes" label="Notes" rows="2" />

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#projectOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveProjectBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Project</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.projects.index'));
    const availableClientsUrl = @json(route('admin.billing.projects.available-clients'));
    const availableDoProjectsUrl = @json(route('admin.billing.projects.available-do-projects'));
    let dataTable = null;
    let clientsCache = [];
    let doProjectsCache = [];
    setTimeout(() => { dataTable = window.dataTable_projectsTable; }, 500);

    async function loadClients () {
        const r = await fetch(availableClientsUrl, { headers: { 'Accept': 'application/json' } });
        const data = await r.json();
        clientsCache = data.data || [];
        document.getElementById('clientSelect').innerHTML =
            '<option value="">— Select a client —</option>' +
            clientsCache.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    }

    async function loadDoProjects () {
        const r = await fetch(availableDoProjectsUrl, { headers: { 'Accept': 'application/json' } });
        const data = await r.json();
        doProjectsCache = data.data || [];
        document.getElementById('doProjectSelect').innerHTML =
            '<option value="">— Not linked —</option>' +
            doProjectsCache.map(p => {
                const label = p.is_default ? `${p.name} (DO Default)` : p.name;
                return `<option value="${p.uuid}">${label}</option>`;
            }).join('');
    }

    loadClients().catch(() => {});
    loadDoProjects().catch(() => {});

    const statusSelect = document.querySelector('select[name="status"]');

    // The date only means anything for a terminated project, and the server
    // rejects it on any other status, so it is only offered for that one.
    function syncTerminatedField () {
        const terminated = statusSelect.value === 'terminated';
        document.getElementById('terminatedAtWrap').classList.toggle('hidden', !terminated);
        if (!terminated) document.querySelector('[name="terminated_at"]').value = '';
    }

    statusSelect?.addEventListener('change', syncTerminatedField);

    function resetForm () {
        document.getElementById('projectForm').reset();
        syncTerminatedField();
    }

    function populate (p) {
        for (const k of ['client_id','name','do_project_uuid','status','terminated_at','markup_type','markup_value','markup_fee','notes']) {
            const el = document.querySelector(`[name="${k}"]`);
            if (el) el.value = p[k] ?? '';
        }
        document.getElementById('projectId').value = p.id;
        syncTerminatedField();
    }

    document.getElementById('createProjectBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('projectOffcanvasLabel').textContent = 'Add Project';
        document.getElementById('projectFormMethod').value = 'POST';
        document.querySelector('select[name="status"]').value = 'active';
        syncTerminatedField();
        HSOverlay.open('#projectOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-project');
        if (!editBtn) return;
        try {
            const r = await fetch(`${indexUrl}/${editBtn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;
            resetForm();
            populate(data.project);
            document.getElementById('projectOffcanvasLabel').textContent = 'Edit Project';
            document.getElementById('projectFormMethod').value = 'PUT';
            HSOverlay.open('#projectOffcanvas');
        } catch { Alert.error('Failed to load project.'); }
    });

    document.getElementById('projectForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('projectFormMethod').value;
        const id = document.getElementById('projectId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') formData.append('_method', 'PUT');
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveProjectBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) { HSOverlay.close('#projectOffcanvas'); if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else { const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Failed to save.'); Alert.html(msg, 'Error'); }
        } catch { Alert.error('An error occurred.'); }
        finally { btn.disabled = false; t.textContent = orig; }
    });

    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.delete-project');
        if (!del) return;
        const ok = await Alert.confirmDelete('This cannot be undone.', 'Delete Project?');
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

@include('admin-v2.billing.partials.markup-fields-script', ['selectName' => 'markup_type'])
