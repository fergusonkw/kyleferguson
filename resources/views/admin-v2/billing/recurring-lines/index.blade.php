@extends('admin-v2.layouts.vertical', ['title' => 'Recurring Items'])

@section('content')
    <x-admin-v2.page-title
        title="Recurring Items"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Recurring Items', 'active' => true],
        ]"
    />

    @isset($currentBusiness)
        <x-admin-v2.card title="{{ $currentBusiness->name }} — Recurring Items">
            <x-slot:headerActions>
                @can('create', \App\Models\Billing\RecurringLineTemplate::class)
                    <button type="button" class="btn btn-primary btn-sm" id="createTemplateBtn">
                        <i data-lucide="plus" class="size-4 me-1"></i> Add Recurring Item
                    </button>
                @endcan
            </x-slot:headerActions>

            <p class="text-sm text-default-500 mb-4">
                Standing charges that attach themselves to every draft in their window — a Forge
                subscription, a domain renewal, a retainer. Priced at what you charge, so no markup is
                applied. An item priced in another currency is converted at the invoice period's rate.
            </p>

            <x-admin-v2.datatable
                id="recurringTable"
                :columns="[
                    ['title' => 'Item', 'data' => 'label'],
                    ['title' => 'Bills to', 'data' => 'applies_to'],
                    ['title' => 'Amount', 'data' => 'amount', 'className' => 'text-end'],
                    ['title' => 'Cadence', 'data' => 'cadence', 'className' => 'text-center'],
                    ['title' => 'Window', 'data' => 'window', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Status', 'data' => 'status', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '110px'],
                ]"
                ajax-url="{{ route('admin.billing.recurring-lines.data') }}"
                :server-side="true"
                empty-message="No recurring items yet. Add one and it will appear on every draft in its window."
            />
        </x-admin-v2.card>
    @else
        <x-admin-v2.alert type="warning" message="Create a business and a client before adding recurring items." />
    @endisset

    <x-admin-v2.offcanvas canvasId="templateOffcanvas" title="Add Recurring Item" size="medium">
        <form id="templateForm">
            @csrf
            <input type="hidden" id="templateId" name="template_id">

            <x-admin-v2.form.input name="label" label="Item" :required="true" placeholder="Laravel Forge" />

            <div class="mb-4">
                <label for="targetSelect" class="form-label">Bills to <span class="text-danger">*</span></label>
                <select id="targetSelect" class="form-select w-full"></select>
                <p class="text-xs text-default-400 mt-1.5">
                    Attach to a client and it appears on every invoice they get. Attach to one of their
                    projects to keep it with that project's work.
                </p>
            </div>
            {{-- The select carries "client:1" or "project:4"; these hold whichever applies. --}}
            <input type="hidden" name="client_id" id="clientIdField">
            <input type="hidden" name="project_id" id="projectIdField">

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <x-admin-v2.form.input name="amount" type="number" step="0.01" min="0" label="Amount" :required="true" placeholder="19.00" />
                </div>
                <x-admin-v2.form.select name="currency" label="Currency" :required="true" :options="$currencyOptions" />
            </div>

            <x-admin-v2.form.select name="cadence" label="Cadence" :required="true" :options="$cadenceOptions" />

            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.input name="active_from" type="date" label="Starts" :required="true" />
                <x-admin-v2.form.input name="active_to" type="date" label="Ends (optional)" />
            </div>
            <p class="text-xs text-default-400 -mt-2 mb-3" id="cadenceHint">
                Quarterly and annual items bill on the anniversary of their start date — one starting in
                March bills in March, June, September and December. Leave the end date blank to keep
                billing indefinitely.
            </p>

            <x-admin-v2.form.textarea name="notes" label="Notes (internal)" rows="2" />

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#templateOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveTemplateBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Item</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.recurring-lines.index'), JSON_UNESCAPED_SLASHES);
    const targetsUrl = @json(route('admin.billing.recurring-lines.targets'), JSON_UNESCAPED_SLASHES);
    let dataTable = null;
    let targetsLoaded = false;
    setTimeout(() => { dataTable = window.dataTable_recurringTable; }, 500);

    const targetSelect = document.getElementById('targetSelect');
    const clientField = document.getElementById('clientIdField');
    const projectField = document.getElementById('projectIdField');

    /** One list of clients with their projects nested beneath them. */
    async function loadTargets () {
        if (targetsLoaded) return;
        try {
            const r = await fetch(targetsUrl, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;

            targetSelect.innerHTML = '<option value="">Select a client or project</option>';
            data.clients.forEach((client) => {
                const group = document.createElement('optgroup');
                group.label = client.name;

                const all = document.createElement('option');
                all.value = `client:${client.id}`;
                all.textContent = `${client.name} — every invoice`;
                all.dataset.currency = client.currency;
                group.appendChild(all);

                client.projects.forEach((project) => {
                    const opt = document.createElement('option');
                    opt.value = `project:${project.id}`;
                    opt.textContent = `${client.name} · ${project.name}`;
                    opt.dataset.currency = client.currency;
                    group.appendChild(opt);
                });

                targetSelect.appendChild(group);
            });
            targetsLoaded = true;
        } catch { Alert.error('Could not load clients.'); }
    }

    /** Split "client:1" / "project:4" into the two fields the API expects. */
    function syncTarget () {
        const [kind, id] = (targetSelect.value || ':').split(':');
        clientField.value = kind === 'client' ? id : '';
        projectField.value = kind === 'project' ? id : '';

        // Default to the client's billing currency: an item priced in anything
        // else converts, which is occasionally wanted and rarely the intent.
        const chosen = targetSelect.selectedOptions[0];
        const currency = chosen?.dataset.currency;
        const currencySelect = document.querySelector('select[name="currency"]');
        if (currency && currencySelect && !currencySelect.dataset.touched) {
            currencySelect.value = currency;
        }
    }

    targetSelect.addEventListener('change', syncTarget);
    document.querySelector('select[name="currency"]')?.addEventListener('change', function () {
        this.dataset.touched = '1';
    });

    function resetForm () {
        document.getElementById('templateForm').reset();
        document.getElementById('templateId').value = '';
        clientField.value = '';
        projectField.value = '';
        delete document.querySelector('select[name="currency"]').dataset.touched;
    }

    document.getElementById('createTemplateBtn')?.addEventListener('click', async () => {
        await loadTargets();
        resetForm();
        document.getElementById('templateOffcanvasLabel').textContent = 'Add Recurring Item';
        document.querySelector('input[name="active_from"]').value = new Date().toISOString().slice(0, 10);
        HSOverlay.open('#templateOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.edit-template');
        if (!btn) return;

        await loadTargets();
        try {
            const r = await fetch(`${indexUrl}/${btn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) return;

            resetForm();
            const t = data.template;
            for (const key of ['label', 'amount', 'currency', 'cadence', 'active_from', 'active_to', 'notes']) {
                const field = document.querySelector(`[name="${key}"]`);
                if (field) field.value = t[key] ?? '';
            }
            targetSelect.value = t.project_id ? `project:${t.project_id}` : `client:${t.client_id}`;
            syncTarget();
            // Whatever is loaded is the operator's own choice, not a default.
            document.querySelector('select[name="currency"]').dataset.touched = '1';
            document.querySelector('select[name="currency"]').value = t.currency;

            document.getElementById('templateId').value = t.id;
            document.getElementById('templateOffcanvasLabel').textContent = 'Edit Recurring Item';
            HSOverlay.open('#templateOffcanvas');
        } catch { Alert.error('Could not load that item.'); }
    });

    document.getElementById('templateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('templateId').value;
        const body = new FormData(e.target);
        if (id) body.append('_method', 'PUT');

        const btn = document.getElementById('saveTemplateBtn');
        const label = btn.querySelector('.btn-text');
        const original = label.textContent;
        btn.disabled = true; label.textContent = 'Saving...';

        try {
            const r = await fetch(id ? `${indexUrl}/${id}` : indexUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body,
            });
            const data = await r.json();

            if (data.success) {
                HSOverlay.close('#templateOffcanvas');
                if (dataTable) dataTable.ajax.reload();
                Alert.toast(data.message, 'success');
            } else {
                const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Could not save.');
                Alert.html(msg, 'Check the form');
            }
        } catch { Alert.error('Could not save.'); }
        finally { btn.disabled = false; label.textContent = original; }
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.delete-template');
        if (!btn) return;

        const ok = await Alert.confirmDelete(
            'It stops appearing on future drafts. Invoices already issued keep their copy of it.',
            'Remove this recurring item?',
        );
        if (!ok) return;

        try {
            const r = await fetch(`${indexUrl}/${btn.dataset.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Could not remove it.');
        } catch { Alert.error('Could not remove it.'); }
    });
});
</script>
@endpush
