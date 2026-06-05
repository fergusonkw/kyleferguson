<div class="flex gap-2">
    <a href="{{ route('admin.billing.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-primary">View</a>
    @if($invoice->status->isEditable())
        <button type="button"
            class="btn btn-sm btn-outline-success"
            onclick="approveInvoice({{ $invoice->id }})">Approve</button>
    @endif
</div>
