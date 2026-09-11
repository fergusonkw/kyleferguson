<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Derived (not persisted) state of a cost line item, used for reporting badges.
 */
enum CostAttributionState: string
{
    case Attributed = 'attributed';
    case Unattributed = 'unattributed';
    case Overhead = 'overhead';

    public function label(): string
    {
        return match ($this) {
            self::Attributed => 'Attributed',
            self::Unattributed => 'Unattributed',
            self::Overhead => 'Overhead',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Attributed => 'success',
            self::Unattributed => 'warning',
            self::Overhead => 'default',
        };
    }
}
