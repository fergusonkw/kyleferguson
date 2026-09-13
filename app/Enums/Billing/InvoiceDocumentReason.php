<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Why an invoice's document was captured.
 */
enum InvoiceDocumentReason: string
{
    case Approved = 'approved';
    case Resent = 'resent';
    case Recorded = 'recorded';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Issued at approval',
            self::Resent => 'Re-issued on resend',
            self::Recorded => 'Recorded after the fact',
        };
    }
}
