<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replaces an invoice's client link, killing the old one.
 *
 * The link is the only thing standing between a stranger and the invoice, so
 * when it reaches the wrong inbox the remedy is a new one — the old URL starts
 * returning 404 the moment this commits, and nothing else about the invoice
 * changes: not its number, its PDF, or what it says.
 *
 * Only a live link can be rotated. A draft's link opens nothing yet, and a
 * voided one already 404s. An approved invoice's link is live — it opens from
 * the email preview — so it can be replaced before the email ever goes out.
 */
final class InvoiceLinkRotator
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rotate(Invoice $invoice): Invoice
    {
        if (! $invoice->status->hasLiveClientLink()) {
            throw new RuntimeException(sprintf(
                'Invoice %s has no live client link to replace — only an approved or sent invoice has one.',
                $invoice->invoice_number,
            ));
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->forceFill(['hosted_view_token' => Invoice::generateHostedViewToken()])->save();

            // Deliberately without either token. The old one is dead, but the
            // new one is a live credential, and the audit log is read by more
            // people than should hold one.
            $this->audit->logSecurity('invoice_link_rotated', $invoice, [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $invoice->business_id,
                'client_id' => $invoice->client_id,
            ], ['billing', 'invoice']);

            return $invoice;
        });
    }
}
