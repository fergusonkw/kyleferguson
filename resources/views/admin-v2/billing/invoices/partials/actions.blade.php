<div class="flex gap-1 justify-center">
    @can('view', $invoice)
        <a href="{{ route('admin.billing.invoices.show', $invoice) }}"
           class="btn btn-sm btn-light" aria-label="Open" title="Open">
            <i data-lucide="eye" class="size-4"></i>
        </a>
    @endcan
    @can('downloadPdf', $invoice)
        <a href="{{ route('admin.billing.invoices.pdf', $invoice) }}"
           class="btn btn-sm btn-light" aria-label="Download PDF" title="Download PDF">
            <i data-lucide="download" class="size-4"></i>
        </a>
    @endcan
</div>
