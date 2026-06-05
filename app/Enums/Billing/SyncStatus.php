<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum SyncStatus: string
{
    case Never = 'never';
    case Success = 'success';
    case Failed = 'failed';
    case Running = 'running';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never synced',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Running => 'Running',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Never => 'default',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Running => 'primary',
        };
    }
}
