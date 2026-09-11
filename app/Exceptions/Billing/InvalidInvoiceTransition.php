<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Invoice;
use RuntimeException;

/**
 * Thrown when an invoice is asked to move somewhere the approval workflow does
 * not allow. Illegal transitions fail loudly rather than being coerced into
 * something plausible — an invoice's status is what the client was told.
 */
final class InvalidInvoiceTransition extends RuntimeException
{
    public static function between(Invoice $invoice, InvoiceStatus $to): self
    {
        return new self(sprintf(
            'Invoice %s cannot move from %s to %s.',
            $invoice->invoice_number,
            $invoice->status->value,
            $to->value,
        ));
    }

    public static function because(Invoice $invoice, string $reason): self
    {
        return new self(sprintf('Invoice %s %s.', $invoice->invoice_number, $reason));
    }
}
