<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Invoice;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice, to the client.
 *
 * Branded per business through the template the invoice snapshotted, so a
 * business that rebrands does not retroactively restyle mail about invoices it
 * already issued. Carries the PDF as an attachment *and* a link to the hosted
 * view: the attachment is what an accounts department files, the link is what
 * shows a running payment status.
 */
final class ClientInvoiceMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    private const FALLBACK_TEMPLATE = 'emails.invoices.default';

    private const FALLBACK_TEXT_TEMPLATE = 'emails.invoices.default-text';

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $business = $this->invoice->business_snapshot ?? [];
        $name = $business['name'] ?? $this->invoice->business->name;

        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                $name,
            ),
            replyTo: array_filter([
                isset($business['contact_email'])
                    ? new Address($business['contact_email'], $name)
                    : null,
            ]),
            subject: sprintf(
                'Invoice %s from %s — %s',
                $this->invoice->invoice_number,
                $name,
                BillingPeriod::label($this->invoice->period),
            ),
        );
    }

    public function content(): Content
    {
        $business = $this->invoice->business_snapshot ?? [];
        $template = $this->templateFor();

        return new Content(
            view: $template,
            text: $this->textTemplateFor($template),
            with: [
                'invoice' => $this->invoice,
                'businessName' => $business['name'] ?? $this->invoice->business->name,
                'contactEmail' => $business['contact_email'] ?? null,
                'accent' => $business['brand_primary_color'] ?? '#c0392b',
                'charcoal' => $business['brand_secondary_color'] ?? '#15161a',
                'clientName' => $this->invoice->client_snapshot['name'] ?? $this->invoice->client->name,
                'periodLabel' => BillingPeriod::label($this->invoice->period),
                'hostedUrl' => route('invoices.hosted.show', $this->invoice->hosted_view_token),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $renderer = app(InvoicePdfRenderer::class);

        return [
            Attachment::fromData(
                fn (): string => $renderer->issuedPdf($this->invoice),
                $renderer->downloadFilename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }

    /**
     * A business may point at its own mail template. If that view is gone,
     * fall back rather than failing to send an invoice at all.
     */
    private function templateFor(): string
    {
        $snapshot = $this->invoice->email_template_view_snapshot;

        return filled($snapshot) && view()->exists($snapshot)
            ? $snapshot
            : self::FALLBACK_TEMPLATE;
    }

    /**
     * The plain-text companion sitting beside the chosen template.
     *
     * The registry treats a `-text` file as a companion of its template rather
     * than a choice of its own, so the pairing is by name. A template that
     * ships without one falls back to the default's text part: a mismatched
     * plain-text body is worse than the default, but both beat sending an
     * HTML-only invoice to a client whose reader shows text.
     */
    private function textTemplateFor(string $template): string
    {
        $companion = $template.'-text';

        return view()->exists($companion) ? $companion : self::FALLBACK_TEXT_TEMPLATE;
    }
}
