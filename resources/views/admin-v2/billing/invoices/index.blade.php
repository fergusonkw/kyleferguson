@extends('admin-v2.layouts.vertical', ['title' => 'Invoices'])

@section('content')
    <x-admin-v2.page-title
        title="Invoices"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Invoices', 'active' => true],
        ]"
    />

    @isset($currentBusiness)
        <x-admin-v2.card title="{{ $currentBusiness->name }} — Invoices">
            <x-slot:headerActions>
                @can('create', \App\Models\Billing\Invoice::class)
                    <button type="button" class="btn btn-primary btn-sm" id="generateInvoiceBtn">
                        <i data-lucide="plus" class="size-4 me-1"></i> Generate Draft
                    </button>
                @endcan
            </x-slot:headerActions>

            <div class="flex flex-wrap gap-3 mb-4">
                <div class="max-w-xs grow">
                    <label for="filterStatus" class="block text-sm font-medium mb-1">Status</label>
                    <select id="filterStatus" class="form-select w-full">
                        <option value="">All statuses</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="max-w-xs grow">
                    <label for="filterPeriod" class="block text-sm font-medium mb-1">Period</label>
                    <select id="filterPeriod" class="form-select w-full">
                        <option value="">All periods</option>
                        @foreach($periods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <x-admin-v2.datatable
                id="invoicesTable"
                :columns="[
                    ['title' => 'Number', 'data' => 'invoice_number'],
                    ['title' => 'Client', 'data' => 'client'],
                    ['title' => 'Period', 'data' => 'period'],
                    ['title' => 'Status', 'data' => 'status', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Total', 'data' => 'total', 'orderable' => false, 'className' => 'text-end'],
                    ['title' => 'Balance', 'data' => 'balance', 'orderable' => false, 'className' => 'text-end'],
                    ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '130px'],
                ]"
                ajax-url="{{ route('admin.billing.invoices.data') }}"
                :server-side="true"
                empty-message="No invoices yet. Generate a draft to get started."
            />
        </x-admin-v2.card>
    @else
        <x-admin-v2.alert type="warning" message="Create a business and a client before generating invoices." />
    @endisset

    <x-admin-v2.offcanvas canvasId="generateOffcanvas" title="Generate Draft Invoice" size="medium">
        <form id="generateForm">
            @csrf
            <x-admin-v2.form.select name="client_id" label="Client" :required="true" :options="[]" placeholder="Select a client" />
            <x-admin-v2.form.select name="period" label="Billing Period" :required="true" :options="$periods" :selected="$defaultPeriod" />
            <p class="text-xs text-default-400 -mt-2 mb-3">
                Costs attributed to this client's projects for the period become hosting lines, with any
                recurring items and carried-forward credits. Re-generating an existing draft refreshes the
                derived lines and keeps anything you added by hand.
            </p>

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#generateOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="generateSubmitBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Generate</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const clientsUrl = @json(route('admin.billing.invoices.available-clients'));
    const generateUrl = @json(route('admin.billing.invoices.generate'));
    let dataTable = null;
    let clientsLoaded = false;
    setTimeout(() => { dataTable = window.dataTable_invoicesTable; }, 500);

    const clientSelect = document.querySelector('select[name="client_id"]');

    /** Filters are applied by re-pointing the table's ajax url. */
    function applyFilters () {
        if (!dataTable) return;
        const params = new URLSearchParams();
        const status = document.getElementById('filterStatus').value;
        const period = document.getElementById('filterPeriod').value;
        if (status) params.set('status', status);
        if (period) params.set('period', period);
        const base = @json(route('admin.billing.invoices.data'));
        dataTable.ajax.url(params.toString() ? `${base}?${params}` : base).load();
    }

    document.getElementById('filterStatus')?.addEventListener('change', applyFilters);
    document.getElementById('filterPeriod')?.addEventListener('change', applyFilters);

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
        } catch { Alert.error('Could not load clients.'); }
    }

    document.getElementById('generateInvoiceBtn')?.addEventListener('click', async () => {
        await loadClients();
        HSOverlay.open('#generateOffcanvas');
    });

    document.getElementById('generateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('generateSubmitBtn');
        const label = btn.querySelector('.btn-text');
        const original = label.textContent;
        btn.disabled = true; label.textContent = 'Generating...';
        try {
            const r = await fetch(generateUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: new FormData(e.target),
            });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#generateOffcanvas');
                Alert.toast(data.message, 'success');
                window.location = data.redirect;
            } else {
                const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || 'Could not generate the draft.');
                Alert.html(msg, 'Generation failed');
            }
        } catch { Alert.error('Could not generate the draft.'); }
        finally { btn.disabled = false; label.textContent = original; }
    });
});
</script>
@endpush
