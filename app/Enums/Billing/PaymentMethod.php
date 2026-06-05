<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum PaymentMethod: string
{
    case Etransfer = 'etransfer';
    case Cheque = 'cheque';
    case Cash = 'cash';
    case Stripe = 'stripe';
    case Interac = 'interac';
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
            self::Etransfer => 'E-Transfer',
            self::Cheque => 'Cheque',
            self::Cash => 'Cash',
            self::Stripe => 'Stripe',
            self::Interac => 'Interac',
            self::Other => 'Other',
        };
    }
}
