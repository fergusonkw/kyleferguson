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
 * Only an issued invoice has a live link to rotate. A draft or an approved
 * invoice has never been in front of anyone, and a voided one already 404s.
 */
final class InvoiceLinkRotator
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rotate(Invoice $invoice): Invoice
    {
        if (! $invoice->status->isIssued()) {
            throw new RuntimeException(sprintf(
                'Invoice %s has no live client link to replace — only a sent invoice has one.',
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
