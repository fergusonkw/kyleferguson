<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum FxRateSource: string
{
    case BankOfCanada = 'bank_of_canada';

    public function label(): string
    {
        return match ($this) {
            self::BankOfCanada => 'Bank of Canada',
        };
    }

    /** Base URL for the Bank of Canada Valet API. */
    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::BankOfCanada => 'https://www.bankofcanada.ca/valet',
        };
    }
}
