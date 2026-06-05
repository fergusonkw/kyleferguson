<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'default',
            self::Approved => 'primary',
            self::Sent => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Void => 'danger',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Approved || $next === self::Void,
            self::Approved => $next === self::Sent || $next === self::Void,
            self::Sent => in_array($next, [self::PartiallyPaid, self::Paid, self::Void], true),
            self::PartiallyPaid => $next === self::Paid || $next === self::Void,
            self::Paid, self::Void => false,
        };
    }
}
