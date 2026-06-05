<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum ProjectStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Terminated => 'Terminated',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Terminated => 'danger',
        };
    }
}
