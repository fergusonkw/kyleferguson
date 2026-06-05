@extends('admin-v2.layouts.vertical', ['title' => 'Invoice '.$invoice->invoice_number])

@section('content')
    <x-admin-v2.page-title
        :title="'Invoice '.$invoice->invoice_number"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Invoices', 'url' => route('admin.billing.invoices.index')],
            ['label' => $invoice->invoice_number, 'active' => true],
        ]"
    />

    @if(session('status'))
        <x-admin-v2.alert type="success" :message="session('status')" dismissible />
    @endif

    {{-- Header bar --}}
    <x-admin-v2.card class="mb-5">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <x-admin-v2.status-badge :label="$invoice->status->label()" :color="$invoice->status->badgeColor()" />
                <div>
                    <p class="text-sm font-semibold">{{ $invoice->client->name }}</p>
                    <p class="text-xs text-default-400">{{ $invoice->period_start->format('F Y') }}</p>
                </div>
            </div>
            <div class="flex gap-2">
                @if($invoice->pdf_path)
                    <a href="{{ route('admin.billing.invoices.download', $invoice) }}" class="btn btn-sm btn-outline-secondary">
                        <i data-lucide="download" class="size-4 me-1"></i> Download
                    </a>
                @endif
                @if($invoice->status->canTransitionTo(\App\Enums\Billing\InvoiceStatus::Approved))
                    <button class="btn btn-sm btn-primary" onclick="transitionInvoice('approve')">Approve</button>
                @endif
                @if($invoice->status->canTransitionTo(\App\Enums\Billing\InvoiceStatus::Sent))
                    <button class="btn btn-sm btn-success" onclick="transitionInvoice('send')">Send to Client</button>
                @endif
                @if($invoice->status->canTransitionTo(\App\Enums\Billing\InvoiceStatus::Void))
                    <button class="btn btn-sm btn-danger" onclick="transitionInvoice('void')">Void</button>
                @endif
            </div>
        </div>
    </x-admin-v2.card>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {{-- Invoice details --}}
        <div class="lg:col-span-2 space-y-5">
            {{-- Summary --}}
            <x-admin-v2.card>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-default-100">
                            <td class="py-2 text-default-400 w-40">Client</td>
                            <td class="py-2 font-medium">{{ $invoice->client->name }}</td>
                        </tr>
                        <tr class="border-b border-default-100">
                            <td class="py-2 text-default-400">Period</td>
                            <td class="py-2">{{ $invoice->period_start->format('F 1, Y') }} – {{ $invoice->period_end->format('F j, Y') }}</td>
                        </tr>
                        <tr class="border-b border-default-100">
                            <td class="py-2 text-default-400">Currency</td>
                            <td class="py-2">{{ $invoice->issue_currency }}</td>
                        </tr>
                        <tr class="border-b border-default-100">
                            <td class="py-2 text-default-400">FX Rate</td>
                            <td class="py-2">1 USD = {{ number_format($invoice->fx_rate_snapshot, 4) }} {{ $invoice->issue_currency }} ({{ $invoice->fx_rate_period }})</td>
                        </tr>
                        @if($invoice->late_fee_terms_snapshot)
                            <tr>
                                <td class="py-2 text-default-400 align-top">Late fee terms</td>
                                <td class="py-2 text-xs text-default-500">{{ $invoice->late_fee_terms_snapshot }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-admin-v2.card>

            {{-- Line items --}}
            <x-admin-v2.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-sm">Line Items</h3>
                    @if($invoice->isDraft())
                        <button class="btn btn-sm btn-outline-primary" onclick="HSOverlay.open('#add-line-modal')">
                            <i data-lucide="plus" class="size-4 me-1"></i> Add Line
                        </button>
                    @endif
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-default-200">
                            <th class="text-left py-2 font-medium text-default-400">Description</th>
                            <th class="text-left py-2 font-medium text-default-400">Type</th>
                            <th class="text-right py-2 font-medium text-default-400">Amount</th>
                            @if($invoice->isDraft())
                                <th class="w-16"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody id="invoice-lines">
                        @foreach($invoice->lines as $line)
                            <tr class="border-b border-default-100" id="line-{{ $line->id }}">
                                <td class="py-2">{{ $line->label }}</td>
                                <td class="py-2 text-default-400 text-xs">{{ $line->line_type->label() }}</td>
                                <td class="py-2 text-right font-mono">{{ number_format($line->amount, 2) }}</td>
                                @if($invoice->isDraft())
                                    <td class="py-2 text-right">
                                        @if($line->line_type->isEditable())
                                            <button class="text-danger hover:text-danger-600 text-xs" onclick="deleteLine({{ $line->id }})">
                                                <i data-lucide="trash-2" class="size-3"></i>
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-default-200">
                            <td class="py-3 font-semibold" colspan="{{ $invoice->isDraft() ? 2 : 2 }}">Subtotal</td>
                            <td class="py-3 text-right font-mono font-semibold" id="subtotal">{{ number_format($invoice->subtotal, 2) }}</td>
                            @if($invoice->isDraft())<td></td>@endif
                        </tr>
                        <tr>
                            <td class="py-2 font-bold text-lg" colspan="{{ $invoice->isDraft() ? 2 : 2 }}">
                                Total {{ $invoice->issue_currency }}
                            </td>
                            <td class="py-2 text-right font-mono font-bold text-lg" id="total">{{ number_format($invoice->total, 2) }}</td>
                            @if($invoice->isDraft())<td></td>@endif
                        </tr>
                    </tfoot>
                </table>
            </x-admin-v2.card>
        </div>

        {{-- Sidebar: payments --}}
        <div class="space-y-5">
            <x-admin-v2.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-sm">Payments</h3>
                    @if(!in_array($invoice->status, [\App\Enums\Billing\InvoiceStatus::Draft, \App\Enums\Billing\InvoiceStatus::Approved, \App\Enums\Billing\InvoiceStatus::Void]))
                        <button class="btn btn-sm btn-outline-success" onclick="HSOverlay.open('#record-payment-modal')">
                            <i data-lucide="plus" class="size-4 me-1"></i> Record
                        </button>
                    @endif
                </div>
                @forelse($invoice->payments->whereNull('deleted_at') as $payment)
                    <div class="flex items-center justify-between py-2 border-b border-default-100">
                        <div>
                            <p class="text-sm font-medium">{{ $invoice->issue_currency }} {{ number_format($payment->amount, 2) }}</p>
                            <p class="text-xs text-default-400">{{ $payment->method->label() }} · {{ $payment->received_at->format('M j, Y') }}</p>
                            @if($payment->reference)
                                <p class="text-xs text-default-400">Ref: {{ $payment->reference }}</p>
                            @endif
                        </div>
                        <button class="text-danger text-xs" onclick="voidPayment({{ $payment->id }})">
                            <i data-lucide="x" class="size-4"></i>
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-default-400">No payments recorded.</p>
                @endforelse
                <div class="pt-3 mt-1">
                    <p class="text-sm font-semibold">
                        Total paid: {{ $invoice->issue_currency }} {{ number_format($invoice->totalPaid(), 2) }}
                    </p>
                    <p class="text-xs text-default-400">
                        Balance: {{ $invoice->issue_currency }} {{ number_format(max(0, $invoice->total - $invoice->totalPaid()), 2) }}
                    </p>
                </div>
            </x-admin-v2.card>

            @if($invoice->hosted_view_token)
                <x-admin-v2.card>
                    <h3 class="font-semibold text-sm mb-2">Hosted Link</h3>
                    <p class="text-xs text-default-400 mb-2">Share this link with the client to view the invoice online.</p>
                    <div class="flex gap-2">
                        <input type="text" class="form-input form-input-sm flex-1 text-xs"
                            value="{{ url('/invoices/'.$invoice->hosted_view_token) }}" readonly>
                        <button class="btn btn-sm btn-outline-secondary"
                            onclick="navigator.clipboard.writeText('{{ url('/invoices/'.$invoice->hosted_view_token) }}')">
                            <i data-lucide="copy" class="size-4"></i>
                        </button>
                    </div>
                </x-admin-v2.card>
            @endif
        </div>
    </div>

    {{-- Add line modal --}}
    <x-admin-v2.modal id="add-line-modal" title="Add Line Item">
        <form id="add-line-form">
            <div class="space-y-4">
                <x-admin-v2.form.input label="Description" name="label" required />
                <x-admin-v2.form.select
                    label="Type"
                    name="line_type"
                    :options="collect($lineTypes)->filter(fn($t) => $t->isEditable())->mapWithKeys(fn($t) => [$t->value => $t->label()])->all()"
                    required
                />
                <x-admin-v2.form.input label="Amount ({{ $invoice->issue_currency }})" name="amount" type="number" step="0.01" required />
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" class="btn btn-outline-secondary" data-hs-overlay="#add-line-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Line</button>
            </div>
        </form>
    </x-admin-v2.modal>

    {{-- Record payment modal --}}
    <x-admin-v2.modal id="record-payment-modal" title="Record Payment">
        <form id="record-payment-form">
            <div class="space-y-4">
                <x-admin-v2.form.input label="Amount ({{ $invoice->issue_currency }})" name="amount" type="number" step="0.01"
                    :value="max(0, $invoice->total - $invoice->totalPaid())" required />
                <x-admin-v2.form.input label="Received on" name="received_at" type="date" :value="date('Y-m-d')" required />
                <x-admin-v2.form.select label="Method" name="method" :options="$paymentMethods" required />
                <x-admin-v2.form.input label="Reference" name="reference" />
                <x-admin-v2.form.textarea label="Notes" name="notes" />
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" class="btn btn-outline-secondary" data-hs-overlay="#record-payment-modal">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </x-admin-v2.modal>
@endsection

@push('scripts')
<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

async function transitionInvoice(action) {
    const confirmMsg = action === 'void' ? 'Are you sure you want to void this invoice?' : null;
    if (confirmMsg && !confirm(confirmMsg)) { return; }

    const res = await fetch('{{ route("admin.billing.invoices.".$invoice->id.".") }}' + action, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
    });
}

async function transitionTo(action) {
    const res = await fetch(`/admin/billing/invoices/{{ $invoice->id }}/${action}`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (json.success) {
        Alert.toast(json.message, 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        Alert.error(json.message);
    }
}

// Wire up transition buttons
document.querySelectorAll('[onclick^="transitionInvoice"]').forEach(btn => {
    const action = btn.getAttribute('onclick').match(/'([^']+)'/)[1];
    btn.onclick = () => transitionTo(action);
});

document.getElementById('add-line-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));

    const res = await fetch('{{ route("admin.billing.invoice-lines.store", $invoice) }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    const json = await res.json();
    if (json.success) {
        HSOverlay.close('#add-line-modal');
        location.reload();
    } else {
        Alert.error(json.message);
    }
});

async function deleteLine(id) {
    if (!confirm('Remove this line?')) { return; }
    const res = await fetch(`/admin/billing/invoice-lines/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (json.success) { location.reload(); } else { Alert.error(json.message); }
}

document.getElementById('record-payment-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));

    const res = await fetch('{{ route("admin.billing.payments.store", $invoice) }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    const json = await res.json();
    if (json.success) {
        HSOverlay.close('#record-payment-modal');
        location.reload();
    } else {
        Alert.error(json.message);
    }
});

async function voidPayment(id) {
    if (!confirm('Void this payment?')) { return; }
    const res = await fetch(`/admin/billing/payments/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (json.success) { location.reload(); } else { Alert.error(json.message); }
}
</script>
@endpush
