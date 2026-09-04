<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Renders an invoice to HTML and to PDF.
 *
 * The template is whichever view the invoice snapshotted at generation time,
 * not the business's current one — reprinting a two-year-old invoice has to
 * produce the document the client actually received. The same render backs the
 * hosted view, so the web page and the PDF can never drift apart.
 */
final class InvoicePdfRenderer
{
    private const DISK = 'local';

    private const FALLBACK_TEMPLATE = 'admin-v2.billing.invoices.templates.default';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * Render the invoice to HTML using its snapshotted template.
     *
     * `$extra` carries anything only one surface needs — the hosted page's
     * payment banner and download link. The PDF passes none, so the printed
     * document stays the canonical record while the web page can add what only
     * a web page can offer. One render either way, so the two cannot drift.
     *
     * @param  array<string, mixed>  $extra
     */
    public function html(Invoice $invoice, array $extra = []): string
    {
        return $this->views->make($this->templateFor($invoice), array_merge([
            'invoice' => $invoice->loadMissing(['topLevelLines.children', 'business', 'client', 'payments']),
        ], $extra))->render();
    }

    /**
     * Render to PDF, store it, and record the path on the invoice.
     * Returns the storage-relative path.
     */
    public function store(Invoice $invoice): string
    {
        $path = $this->pathFor($invoice);

        Storage::disk(self::DISK)->makeDirectory(dirname($path));

        Pdf::html($this->html($invoice))
            ->format('letter')
            ->margins(14, 14, 14, 14)
            ->save(Storage::disk(self::DISK)->path($path));

        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * The stored PDF, rendering and storing it if it does not exist yet.
     */
    public function ensureStored(Invoice $invoice): string
    {
        if ($invoice->pdf_path !== null && Storage::disk(self::DISK)->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return $this->store($invoice);
    }

    public function contents(Invoice $invoice): string
    {
        $path = $this->ensureStored($invoice);
        $contents = Storage::disk(self::DISK)->get($path);

        if ($contents === null) {
            throw new RuntimeException("Invoice PDF for {$invoice->invoice_number} could not be read back from storage.");
        }

        return $contents;
    }

    public function downloadFilename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }

    /**
     * Invoices are grouped per business so one business's documents can be
     * archived or handed over without touching another's.
     */
    private function pathFor(Invoice $invoice): string
    {
        return sprintf('invoices/%d/%s.pdf', $invoice->business_id, $invoice->invoice_number);
    }

    /**
     * A business can point at its own template. If that view has since been
     * removed, fall back rather than failing to produce an invoice at all —
     * a differently-styled document beats no document.
     */
    private function templateFor(Invoice $invoice): string
    {
        $snapshot = $invoice->template_view_snapshot;

        if (filled($snapshot) && $this->views->exists($snapshot)) {
            return $snapshot;
        }

        return self::FALLBACK_TEMPLATE;
    }
}
