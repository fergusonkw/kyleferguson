<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Billing periods are calendar months identified as `YYYY-MM`.
 */
final class BillingPeriod
{
    /**
     * Day of the month after which the previous period is considered closed and
     * no longer worth re-syncing daily.
     */
    private const PREVIOUS_PERIOD_GRACE_DAYS = 5;

    public static function current(?Carbon $at = null): string
    {
        return ($at ?? now())->format('Y-m');
    }

    public static function previous(?Carbon $at = null): string
    {
        return ($at ?? now())->copy()->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Periods a daily sync should refresh: always the current month, plus the
     * previous month for a few days while the provider settles it.
     *
     * @return list<string>
     */
    public static function openPeriods(?Carbon $at = null): array
    {
        $at ??= now();
        $periods = [self::current($at)];

        if ($at->day <= self::PREVIOUS_PERIOD_GRACE_DAYS) {
            $periods[] = self::previous($at);
        }

        return $periods;
    }

    public static function assertValid(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("Billing period must be YYYY-MM, got [{$period}].");
        }
    }

    public static function isValid(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public static function start(string $period): Carbon
    {
        self::assertValid($period);

        return Carbon::createFromFormat('Y-m', $period)->startOfMonth();
    }

    public static function end(string $period): Carbon
    {
        return self::start($period)->endOfMonth();
    }

    public static function label(string $period): string
    {
        return self::start($period)->format('F Y');
    }
}
