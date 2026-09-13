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
                    <button type="button" class="btn btn-light btn-sm" id="pastInvoiceBtn">
                        <i data-lucide="history" class="size-4 me-1"></i> Record Past Invoice
                    </button>
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

    <x-admin-v2.offcanvas canvasId="pastOffcanvas" title="Record Past Invoice" size="medium">
        <form id="pastForm">
            @csrf
            <p class="text-sm text-default-500 mb-4">
                For an invoice the client received before this system kept the books. It takes the next
                invoice number{{ $nextInvoiceNumber ? ' — '.$nextInvoiceNumber.' —' : '' }} and starts empty:
                you add its lines as they were on the original, then record it as issued on its real date,
                paid if it was. Nothing is emailed. Entering several? Go oldest first, so the numbers run in
                date order.
            </p>
            <x-admin-v2.form.select name="client_id" id="past_client_id" label="Client" :required="true" :options="[]" placeholder="Select a client" />
            <div class="mb-5">
                <label for="past_period" class="form-label">Month it billed <span class="text-danger">*</span></label>
                <input type="month" id="past_period" name="period" class="form-input" required
                       max="{{ now()->format('Y-m') }}">
                <p class="text-xs text-default-400 mt-1">A client has one invoice per month, so this has to be a month without one.</p>
            </div>

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#pastOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="pastSubmitBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Start</span>
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

    // The generate form and the past-invoice form each pick a client.
    const clientSelects = document.querySelectorAll('select[name="client_id"]');

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
            clientSelects.forEach((select) => {
                select.innerHTML = '<option value="">Select a client</option>';
                data.clients.forEach((c) => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    select.appendChild(opt);
                });
            });
            clientsLoaded = true;
        } catch { Alert.error('Could not load clients.'); }
    }

    document.getElementById('generateInvoiceBtn')?.addEventListener('click', async () => {
        await loadClients();
        HSOverlay.open('#generateOffcanvas');
    });

    document.getElementById('pastInvoiceBtn')?.addEventListener('click', async () => {
        await loadClients();
        HSOverlay.open('#pastOffcanvas');
    });

    /** Both forms create an invoice and land on it. */
    function submitCreateForm (form, { url, overlay, button, busy, failure, title }) {
        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById(button);
            const label = btn.querySelector('.btn-text');
            const original = label.textContent;
            btn.disabled = true; label.textContent = busy;
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: new FormData(e.target),
                });
                const data = await r.json();
                if (data.success) {
                    HSOverlay.close(overlay);
                    Alert.toast(data.message, 'success');
                    window.location = data.redirect;
                } else {
                    const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || failure);
                    Alert.html(msg, title);
                }
            } catch { Alert.error(failure); }
            finally { btn.disabled = false; label.textContent = original; }
        });
    }

    submitCreateForm(document.getElementById('generateForm'), {
        url: generateUrl,
        overlay: '#generateOffcanvas',
        button: 'generateSubmitBtn',
        busy: 'Generating...',
        failure: 'Could not generate the draft.',
        title: 'Generation failed',
    });

    submitCreateForm(document.getElementById('pastForm'), {
        url: @json(route('admin.billing.invoices.past.start')),
        overlay: '#pastOffcanvas',
        button: 'pastSubmitBtn',
        busy: 'Starting...',
        failure: 'Could not start the past invoice.',
        title: 'Not started',
    });
});
</script>
@endpush
