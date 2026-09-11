<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceDocument;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Renders an invoice to HTML and to PDF.
 *
 * Nothing is written to disk. A PDF is rendered when it is asked for, and the
 * durable record of what a client was sent is the issued HTML captured into
 * `invoice_documents` — self-contained, so it reprints identically forever
 * and is covered by the database's backups rather than by a filesystem a host
 * may wipe on deploy.
 *
 * Two kinds of copy come out of here:
 *
 * - **Current** — rendered live from the invoice's snapshotted template and
 *   data, so a client downloading after paying gets a copy stamped Paid.
 * - **As issued** — the captured HTML, exactly what went out at approval or on
 *   the latest resend. The invoice email attaches this one.
 *
 * The template is whichever view the invoice snapshotted at generation time,
 * not the business's current one. The same render backs the hosted view, so
 * the web page and the current PDF can never drift apart.
 */
final class InvoicePdfRenderer
{
    private const FALLBACK_TEMPLATE = 'admin-v2.billing.invoices.templates.default';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly BusinessLogoStore $logos,
        private readonly InvoiceFontStore $fonts,
        private readonly DocumentKitStore $kits,
    ) {}

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
        $template = $this->templateFor($invoice);

        return $this->views->make($template, array_merge([
            'invoice' => $invoice->loadMissing(['topLevelLines.children', 'business', 'client', 'payments']),

            // Inlined rather than linked: Browsershot renders from an HTML
            // string with no document base, so a relative URL would resolve to
            // nothing and the logo would vanish from every PDF.
            'logoDataUri' => $this->logos->dataUri($this->snapshotLogoId($invoice)),

            // Scoped to the template so an invoice carries only the faces it
            // sets its own text in, rather than every family the application
            // has vendored for every template.
            'fontFaceCss' => $this->fonts->faceCss($template),

            // Empty for a template that brings its own CSS, as the default one
            // does. Same reasoning as the logo and the fonts: a linked
            // stylesheet is not dependable from a bare HTML string.
            'kitCss' => $this->kits->cssFor($template),
        ], $extra))->render();
    }

    /**
     * Capture the document as it stands now — the record of what the client
     * is being sent. Called at approval and on every resend; each capture is
     * kept, so every send can be reproduced.
     */
    public function freeze(Invoice $invoice, InvoiceDocumentReason $reason): InvoiceDocument
    {
        $document = $invoice->documents()->create([
            'reason' => $reason,
            'html' => $this->html($invoice),
        ]);

        $invoice->unsetRelation('issuedDocument');

        return $document;
    }

    /**
     * The current copy: rendered now, showing payments received since.
     */
    public function pdf(Invoice $invoice): string
    {
        return $this->toPdf($this->html($invoice));
    }

    /**
     * The copy the client was most recently sent. An invoice with nothing
     * captured — issued before documents were kept — has only its current
     * copy to offer.
     */
    public function issuedPdf(Invoice $invoice): string
    {
        $document = $invoice->issuedDocument()->first();

        return $document !== null
            ? $this->toPdf($document->html)
            : $this->pdf($invoice);
    }

    public function downloadFilename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }

    private function toPdf(string $html): string
    {
        return Pdf::html($html)
            ->format('letter')
            ->margins(14, 14, 14, 14)
            ->generatePdfContent();
    }

    private function snapshotLogoId(Invoice $invoice): ?int
    {
        $logoId = $invoice->business_snapshot['logo_id'] ?? null;

        return is_numeric($logoId) ? (int) $logoId : null;
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
