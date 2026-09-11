<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Where a legal entity stands against the GST/HST small-supplier threshold.
 */
enum ThresholdLevel: string
{
    case Clear = 'clear';
    case Approaching = 'approaching';
    case Exceeded = 'exceeded';
    case Registered = 'registered';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Below threshold',
            self::Approaching => 'Approaching threshold',
            self::Exceeded => 'Threshold exceeded',
            self::Registered => 'GST/HST registered',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Clear => 'success',
            self::Approaching => 'warning',
            self::Exceeded => 'danger',
            self::Registered => 'primary',
        };
    }

    /**
     * How serious the level is, for deciding whether it has worsened since the
     * operator was last told. Registered has nothing left to warn about.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Clear, self::Registered => 0,
            self::Approaching => 1,
            self::Exceeded => 2,
        };
    }
}
