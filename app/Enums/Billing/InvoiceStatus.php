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

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'default',
            self::Approved => 'info',
            self::Sent => 'primary',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Void => 'danger',
        };
    }

    /**
     * Statuses this one may move to. Payment-derived statuses are reached by
     * recording payments, never by a direct transition.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Void],
            self::Approved => [self::Sent, self::Void],
            self::Sent => [self::PartiallyPaid, self::Paid, self::Void],
            self::PartiallyPaid => [self::Paid, self::Void],
            self::Paid => [self::PartiallyPaid, self::Void],
            self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the invoice's lines and totals may still be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the invoice counts as issued to the client — the point past
     * which it becomes immutable.
     */
    public function isIssued(): bool
    {
        return match ($this) {
            self::Sent, self::PartiallyPaid, self::Paid => true,
            default => false,
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Paid, self::Void => false,
            default => true,
        };
    }
}
