@extends('admin-v2.layouts.vertical', ['title' => 'Businesses'])

@section('content')
    <x-admin-v2.page-title
        title="Businesses"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Businesses', 'active' => true]]"
    />

    <x-admin-v2.card title="All Businesses">
        <x-slot:headerActions>
            @can('create', \App\Models\Billing\Business::class)
                <button type="button" class="btn btn-primary btn-sm" id="createBusinessBtn">
                    <i data-lucide="plus" class="size-4 me-1"></i> Add Business
                </button>
            @endcan
        </x-slot:headerActions>

        <x-admin-v2.datatable
            id="businessesTable"
            :columns="[
                ['title' => 'ID', 'data' => 'id', 'className' => 'text-center', 'width' => '60px'],
                ['title' => 'Name', 'data' => 'name'],
                ['title' => 'Contact', 'data' => 'contact_email'],
                ['title' => 'Currency', 'data' => 'default_currency', 'className' => 'text-center', 'width' => '80px'],
                ['title' => 'Clients', 'data' => 'clients_count', 'className' => 'text-center', 'width' => '80px'],
                ['title' => 'Providers', 'data' => 'providers_count', 'className' => 'text-center', 'width' => '80px'],
                ['title' => 'Tax Status', 'data' => 'tax_registered', 'orderable' => false, 'className' => 'text-center'],
                ['title' => 'Actions', 'data' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'text-center', 'width' => '120px'],
            ]"
            ajax-url="{{ route('admin.billing.businesses.data') }}"
            :server-side="true"
        />
    </x-admin-v2.card>

    <x-admin-v2.offcanvas canvasId="businessOffcanvas" title="Add Business" size="large">
        <form id="businessForm">
            @csrf
            <input type="hidden" id="businessId" name="business_id">
            <input type="hidden" id="businessFormMethod" value="POST">

            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Identity</p>
            <x-admin-v2.form.input name="name" label="Business Name" :required="true" placeholder="e.g. Kyle Ferguson Consulting" />
            <x-admin-v2.form.input name="legal_name" label="Legal Name" placeholder="e.g. Kyle Ferguson Consulting Inc." />
            <x-admin-v2.form.textarea name="address" label="Business Address" rows="3" placeholder="Street, City, Province, Postal" />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Contact &amp; Notifications</p>
            <x-admin-v2.form.input name="contact_email" type="email" label="Contact Email (shown on invoices)" :required="true" />
            <x-admin-v2.form.input name="notification_email" type="email" label="Notification Email (for draft alerts)" :required="true" />
            <x-admin-v2.form.input name="daily_reminder_time" type="time" label="Daily Reminder Time" :required="true" />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Invoicing</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.input name="invoice_number_prefix" label="Invoice # Prefix" :required="true" placeholder="INV-" />
                <x-admin-v2.form.input name="default_currency" label="Default Currency" :required="true" placeholder="CAD" maxlength="3" />
            </div>
            <div class="mb-4">
                <label class="text-sm font-medium mb-1.5 block">Supported Currencies <span class="text-danger">*</span></label>
                <div class="flex gap-3" id="supportedCurrenciesContainer">
                    @foreach(['CAD', 'USD', 'EUR', 'GBP'] as $code)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" class="form-checkbox" name="supported_currencies[]" value="{{ $code }}">
                            <span class="text-sm">{{ $code }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <x-admin-v2.form.input name="fx_source" label="FX Rate Source" :required="true" placeholder="bank_of_canada" />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Branding</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin-v2.form.input name="brand_primary_color" label="Primary Color" placeholder="#1e40af" />
                <x-admin-v2.form.input name="brand_secondary_color" label="Secondary Color" placeholder="#9333ea" />
            </div>

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Tax &amp; Late Fees</p>
            <x-admin-v2.form.input name="tax_registered_from" type="date" label="GST/HST Registered From (optional)" />
            <x-admin-v2.form.textarea name="late_fee_terms" label="Late Fee Terms (rendered on invoice footer)" rows="2"
                placeholder="A 2% monthly interest charge applies to balances unpaid after 30 days." />

            <hr class="border-default-200 my-4">
            <p class="text-xs font-semibold uppercase text-default-400 tracking-wider mb-3">Cost Thresholds</p>
            <x-admin-v2.form.input name="trailing_12mo_threshold_usd" type="number" step="0.01" min="0"
                label="Trailing 12-Month Cost Threshold (USD, optional)"
                placeholder="e.g. 50000 — warn when attributed costs exceed this" />

            <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                <button type="button" class="btn btn-light" data-hs-overlay="#businessOffcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBusinessBtn">
                    <i data-lucide="check" class="size-4 me-1"></i>
                    <span class="btn-text">Save Business</span>
                </button>
            </div>
        </form>
    </x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const indexUrl = @json(route('admin.billing.businesses.index'));
    let dataTable = null;
    setTimeout(() => { dataTable = window.dataTable_businessesTable; }, 500);

    function resetForm () {
        document.getElementById('businessForm').reset();
        document.querySelectorAll('input[name="supported_currencies[]"]').forEach(cb => { cb.checked = false; });
    }

    function populateForm (b) {
        document.getElementById('businessId').value = b.id;
        for (const k of ['name','legal_name','address','contact_email','notification_email','brand_primary_color',
            'brand_secondary_color','invoice_number_prefix','default_currency','fx_source','tax_registered_from',
            'daily_reminder_time','late_fee_terms','trailing_12mo_threshold_usd']) {
            const el = document.querySelector(`[name="${k}"]`);
            if (el) el.value = b[k] ?? '';
        }
        document.querySelectorAll('input[name="supported_currencies[]"]').forEach(cb => {
            cb.checked = (b.supported_currencies || []).includes(cb.value);
        });
    }

    document.getElementById('createBusinessBtn')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('businessOffcanvasLabel').textContent = 'Add Business';
        document.getElementById('businessFormMethod').value = 'POST';
        document.querySelector('input[name="invoice_number_prefix"]').value = 'INV-';
        document.querySelector('input[name="default_currency"]').value = 'CAD';
        document.querySelector('input[name="fx_source"]').value = 'bank_of_canada';
        document.querySelector('input[name="daily_reminder_time"]').value = '08:00';
        const cad = document.querySelector('input[name="supported_currencies[]"][value="CAD"]');
        if (cad) cad.checked = true;
        HSOverlay.open('#businessOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-business');
        if (!editBtn) return;
        const id = editBtn.dataset.id;
        try {
            const r = await fetch(`${indexUrl}/${id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) {
                resetForm();
                populateForm(data.business);
                document.getElementById('businessOffcanvasLabel').textContent = 'Edit Business';
                document.getElementById('businessFormMethod').value = 'PUT';
                HSOverlay.open('#businessOffcanvas');
            }
        } catch { Alert.error('Failed to load business.'); }
    });

    document.getElementById('businessForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const method = document.getElementById('businessFormMethod').value;
        const id = document.getElementById('businessId').value;
        const formData = new FormData(e.target);
        if (method === 'PUT') formData.append('_method', 'PUT');
        const url = id ? `${indexUrl}/${id}` : indexUrl;
        const btn = document.getElementById('saveBusinessBtn');
        const t = btn.querySelector('.btn-text');
        const orig = t.textContent;
        btn.disabled = true; t.textContent = 'Saving...';
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: formData });
            const data = await r.json();
            if (data.success) {
                HSOverlay.close('#businessOffcanvas');
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
        const del = e.target.closest('.delete-business');
        if (!del) return;
        const id = del.dataset.id;
        const ok = await Alert.confirmDelete('This action cannot be undone.', 'Delete Business?');
        if (!ok) return;
        try {
            const r = await fetch(`${indexUrl}/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
            const data = await r.json();
            if (data.success) { if (dataTable) dataTable.ajax.reload(); Alert.toast(data.message, 'success'); }
            else Alert.error(data.message || 'Failed to delete.');
        } catch { Alert.error('Failed to delete.'); }
    });
});
</script>
@endpush
