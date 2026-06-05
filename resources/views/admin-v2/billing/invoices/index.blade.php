@extends('admin-v2.layouts.vertical', ['title' => 'Invoices'])

@section('content')
    <x-admin-v2.page-title
        title="Invoices"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Invoices', 'active' => true],
        ]"
    />

    @if(session('status'))
        <x-admin-v2.alert type="success" :message="session('status')" dismissible />
    @endif

    <x-admin-v2.card>
        <div class="flex items-center justify-between mb-4">
            <div class="flex gap-2">
                <select id="statusFilter" class="form-select form-select-sm w-40">
                    <option value="">All statuses</option>
                    @foreach(\App\Enums\Billing\InvoiceStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button"
                class="btn btn-primary btn-sm"
                onclick="HSOverlay.open('#generate-invoice-modal')">
                <i data-lucide="plus" class="size-4 me-1"></i> Generate Draft
            </button>
        </div>

        <x-admin-v2.datatable
            id="invoices-table"
            :columns="['Invoice #', 'Client', 'Period', 'Total', 'Status', 'Created', 'Actions']"
            :url="route('admin.billing.invoices.data')"
        />
    </x-admin-v2.card>

    {{-- Generate invoice modal --}}
    <x-admin-v2.modal id="generate-invoice-modal" title="Generate Invoice Draft">
        <form id="generate-invoice-form">
            <div class="space-y-4">
                <x-admin-v2.form.select
                    label="Client"
                    name="client_id"
                    :options="[]"
                    required
                />
                <x-admin-v2.form.input
                    label="Period (YYYY-MM)"
                    name="period"
                    type="text"
                    placeholder="2026-06"
                    :value="date('Y-m')"
                    required
                />
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" class="btn btn-outline-secondary" data-hs-overlay="#generate-invoice-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate</button>
            </div>
        </form>
    </x-admin-v2.modal>
@endsection

@push('scripts')
<script>
const table = window.dataTable_invoicesTable;

document.getElementById('statusFilter').addEventListener('change', function() {
    const url = new URL('{{ route("admin.billing.invoices.data") }}');
    url.searchParams.set('status', this.value);
    // Reinitialize or use custom params — handled via column search
    if (table) {
        table.ajax.url(url.toString()).load();
    }
});

document.getElementById('generate-invoice-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const data = new FormData(this);

    const res = await fetch('{{ route("admin.billing.invoices.generate") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(Object.fromEntries(data)),
    });

    const json = await res.json();

    if (json.success) {
        HSOverlay.close('#generate-invoice-modal');
        window.location.href = '/admin/billing/invoices/' + json.invoice_id;
    } else {
        Alert.error(json.message || 'Failed to generate invoice.');
    }
});
</script>
@endpush
