<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use App\Mail\Billing\ClientInvoiceMail;
use App\Models\Billing\Invoice;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Emails an invoice that has already been sent, again.
 *
 * Mostly this is the remedy for a first send that went to the wrong address,
 * so it can do the two things that remedy needs in the same step: replace the
 * client link, so whoever received the first email can no longer read it, and
 * move the due date, so the client is not held to terms that started running
 * before they had the invoice.
 *
 * `sent_at` moves to now. It means "the client has it", and until this send
 * the client did not. The due date does not follow it on its own — it was set
 * at approval from the payment terms and is what every overdue calculation
 * reads — so restarting the client's clock is an explicit choice made in the
 * same request.
 */
final class InvoiceResender
{
    public function __construct(
        private readonly InvoiceLinkRotator $links,
        private readonly InvoicePdfRenderer $pdf,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $clientMessage  the message this email carries; null for none
     */
    public function resend(
        Invoice $invoice,
        string $recipient,
        ?string $clientMessage,
        ?CarbonInterface $dueOn = null,
        bool $replaceLink = false,
    ): Invoice {
        if (! $invoice->status->isIssued()) {
            throw new RuntimeException(sprintf(
                'Invoice %s has not been sent yet — use Mark Sent to send it the first time.',
                $invoice->invoice_number,
            ));
        }

        $previousSentAt = $invoice->sent_at;
        $previousDueOn = $invoice->due_on;

        // Before the mail is built, so the email carries the new link. If the
        // send then fails the old link stays dead, which is the right way
        // round: whoever holds it should not have it either way.
        if ($replaceLink) {
            $this->links->rotate($invoice);
        }

        $dueOnChanged = $dueOn !== null && $dueOn->toDateString() !== $previousDueOn?->toDateString();

        if ($dueOnChanged) {
            $invoice->forceFill(['due_on' => $dueOn->toDateString()])->save();
        }

        // The document captured at approval can carry a due date or a balance
        // that has since changed. A fresh capture, from the same snapshotted
        // template, keeps its look and gains only what is true now — and the
        // earlier capture stays, as the record of the first send.
        $this->pdf->freeze($invoice->refresh(), InvoiceDocumentReason::Resent);

        // Carried by this email but saved only with `sent_at` below: the
        // invoice's message is what the client last received, and a send
        // that fails leaves them holding the previous one.
        $previousMessage = $invoice->client_message;
        $invoice->client_message = $clientMessage;

        Mail::to($recipient)->send(new ClientInvoiceMail($invoice));

        // Only once the mail is handed off, the same promise Mark Sent makes.
        $invoice->forceFill(['sent_at' => now()])->save();

        $this->audit->logCritical(
            'invoice_resent',
            $invoice,
            [
                'sent_at' => $previousSentAt?->toIso8601String(),
                'due_on' => $previousDueOn?->toDateString(),
            ],
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $invoice->business_id,
                'client_id' => $invoice->client_id,
                'recipient' => $recipient,
                'sent_at' => $invoice->sent_at->toIso8601String(),
                'due_on' => $invoice->due_on?->toDateString(),
                'link_replaced' => $replaceLink,
                'message_changed' => $invoice->client_message !== $previousMessage,
            ],
            ['billing', 'invoice'],
        );

        return $invoice;
    }
}
