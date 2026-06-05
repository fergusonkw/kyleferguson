<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum InvoiceLineType: string
{
    case Hosting = 'hosting';
    case Recurring = 'recurring';
    case Manual = 'manual';
    case Adjustment = 'adjustment';
    case Discount = 'discount';
    case Credit = 'credit';
    case Tax = 'tax';

    public function label(): string
    {
        return match ($this) {
            self::Hosting => 'Hosting',
            self::Recurring => 'Recurring',
            self::Manual => 'Manual',
            self::Adjustment => 'Adjustment',
            self::Discount => 'Discount',
            self::Credit => 'Credit',
            self::Tax => 'Tax',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Manual, self::Adjustment, self::Discount, self::Credit], true);
    }
}
