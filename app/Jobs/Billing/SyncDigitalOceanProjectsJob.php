<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\CostProvider;
use App\Services\Billing\DigitalOcean\ProjectSync;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls projects + resources from DigitalOcean for one cost provider and
 * updates the local resource/assignment tables. Idempotent: re-running
 * over an unchanged remote state is a no-op aside from refreshing last_seen_at.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
#[MaxExceptions(3)]
final class SyncDigitalOceanProjectsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $costProviderId) {}

    public function handle(ProjectSync $sync): void
    {
        $provider = CostProvider::find($this->costProviderId);

        if ($provider === null) {
            Log::warning('SyncDigitalOceanProjectsJob: cost provider not found', [
                'cost_provider_id' => $this->costProviderId,
            ]);

            return;
        }

        if (! $provider->enabled) {
            Log::info('SyncDigitalOceanProjectsJob: provider disabled, skipping', [
                'cost_provider_id' => $provider->id,
            ]);

            return;
        }

        $observed = $sync->syncProjectsAndResources($provider);

        Log::info('SyncDigitalOceanProjectsJob: completed', [
            'cost_provider_id' => $provider->id,
            'resources_observed' => $observed,
        ]);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottlesExceptions(5, 10 * 60)];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return now()->addHour()->toDateTimeImmutable();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncDigitalOceanProjectsJob: failed permanently', [
            'cost_provider_id' => $this->costProviderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
