<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum Cadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annual => 'Annual',
        };
    }

    /**
     * Whether this template is active during the given period (YYYY-MM).
     */
    public function isActiveForPeriod(string $period): bool
    {
        [$year, $month] = explode('-', $period);

        return match ($this) {
            self::Monthly => true,
            self::Quarterly => in_array((int) $month, [1, 4, 7, 10], true),
            self::Annual => (int) $month === 1,
        };
    }
}
