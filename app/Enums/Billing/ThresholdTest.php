<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * The two ways out of small-supplier status: too much in a single calendar
 * quarter, or too much across four consecutive ones.
 */
enum ThresholdTest: string
{
    case SingleQuarter = 'single_quarter';
    case FourQuarters = 'four_quarters';

    public function label(): string
    {
        return match ($this) {
            self::SingleQuarter => 'Single calendar quarter',
            self::FourQuarters => 'Four consecutive calendar quarters',
        };
    }
}
