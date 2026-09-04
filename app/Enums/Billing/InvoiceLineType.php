<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum InvoiceLineType: string
{
    /** Derived from ingested provider costs. Never hand-edited. */
    case Hosting = 'hosting';

    /** Applied from a recurring line template. */
    case Recurring = 'recurring';

    /** Ad-hoc line added at review time. */
    case Manual = 'manual';

    /** Corrects a previously invoiced amount, preserving the audit trail. */
    case Adjustment = 'adjustment';

    case Discount = 'discount';

    /** Carried forward from an overpayment on an earlier invoice. */
    case Credit = 'credit';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Types an operator may add or edit by hand. Hosting lines are derived, so
     * corrections happen through an adjustment line instead.
     *
     * @return list<self>
     */
    public static function operatorEditable(): array
    {
        return [self::Manual, self::Adjustment, self::Discount, self::Credit];
    }

    public function label(): string
    {
        return match ($this) {
            self::Hosting => 'Hosting',
            self::Recurring => 'Recurring',
            self::Manual => 'Manual',
            self::Adjustment => 'Adjustment',
            self::Discount => 'Discount',
            self::Credit => 'Credit',
        };
    }

    public function isOperatorEditable(): bool
    {
        return in_array($this, self::operatorEditable(), true);
    }

    /**
     * Whether amounts of this type are expected to reduce the invoice total.
     */
    public function isNegative(): bool
    {
        return match ($this) {
            self::Discount, self::Credit => true,
            default => false,
        };
    }
}
