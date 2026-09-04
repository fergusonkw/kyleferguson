<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use Illuminate\Support\Carbon;

enum RecurringCadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annually = 'annually';

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
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annually => 'Annually',
        };
    }

    /**
     * Whether a template starting on `$activeFrom` bills in the month that
     * `$periodStart` opens.
     *
     * Quarterly and annual items bill on the anniversary month of their start
     * date, so a template active from March bills in March, June, September
     * and December when quarterly.
     */
    public function billsIn(Carbon $activeFrom, Carbon $periodStart): bool
    {
        $monthsElapsed = ($periodStart->year - $activeFrom->year) * 12
            + ($periodStart->month - $activeFrom->month);

        if ($monthsElapsed < 0) {
            return false;
        }

        return $monthsElapsed % $this->intervalInMonths() === 0;
    }

    public function intervalInMonths(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annually => 12,
        };
    }
}
