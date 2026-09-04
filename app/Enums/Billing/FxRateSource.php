<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum FxRateSource: string
{
    case BankOfCanada = 'bank_of_canada';

    /**
     * Rates that need no external lookup (identity pairs, manual overrides).
     */
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::BankOfCanada => 'Bank of Canada',
            self::Internal => 'Internal',
        };
    }
}
