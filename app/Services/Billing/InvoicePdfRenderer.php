<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Support\Facades\Storage;

final class InvoicePdfRenderer
{
    /**
     * Render the invoice to HTML and write it to storage.
     * Returns the storage path of the rendered file.
     *
     * Swap the Storage::put() call for a PDF library (e.g. DomPDF, Browsershot)
     * when one is available — the interface stays the same.
     */
    public function render(Invoice $invoice): string
    {
        $html = view($invoice->template_view_snapshot, ['invoice' => $invoice])->render();

        $path = 'invoices/'.$invoice->id.'/'.$invoice->invoice_number.'.html';
        Storage::put($path, $html);

        return $path;
    }
}
