<?php

declare(strict_types=1);

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Liveness probe for the queue worker. The scheduler dispatches this every
 * few minutes; the job records when it actually ran. If the recorded time
 * falls too far behind, the worker is not processing jobs (the health check
 * and queue:monitor-health both read this).
 */
final class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'observability:queue_heartbeat_at';

    /**
     * When the heartbeat last completed, or null if it has never run.
     */
    public static function lastRunAt(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return is_numeric($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp)
            : null;
    }

    /**
     * Seconds since the heartbeat last completed, or null if never run.
     */
    public static function ageInSeconds(): ?int
    {
        $lastRun = self::lastRunAt();

        return $lastRun === null ? null : max(0, now()->timestamp - $lastRun->timestamp);
    }

    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, now()->timestamp);
    }
}
