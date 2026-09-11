<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum PaymentMethod: string
{
    case ETransfer = 'etransfer';
    case Cheque = 'cheque';
    case Cash = 'cash';
    case Interac = 'interac';
    case Stripe = 'stripe';
    case Other = 'other';

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
            self::ETransfer => 'e-Transfer',
            self::Cheque => 'Cheque',
            self::Cash => 'Cash',
            self::Interac => 'Interac',
            self::Stripe => 'Stripe',
            self::Other => 'Other',
        };
    }

    /**
     * Methods settled by an external processor rather than recorded by hand.
     * v1 records everything manually; this marks where webhooks will land.
     */
    public function isAutomated(): bool
    {
        return match ($this) {
            self::Stripe, self::Interac => true,
            default => false,
        };
    }
}
