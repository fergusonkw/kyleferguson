<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Exceptions\Billing\UnsupportedProviderCapability;
use App\Models\Billing\CostProvider;
use App\Services\Billing\ProviderAdapterRegistry;
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
 * Pulls a provider's project/resource inventory and updates the local resource
 * and assignment tables. Idempotent: re-running over an unchanged remote state
 * is a no-op aside from refreshing last_seen_at.
 *
 * Only providers with a resource inventory implement this; a provider whose
 * whole account is one subscription is skipped rather than failed.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
#[MaxExceptions(3)]
final class SyncProviderResourcesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $costProviderId) {}

    public function handle(ProviderAdapterRegistry $registry): void
    {
        $provider = CostProvider::find($this->costProviderId);

        if ($provider === null) {
            Log::warning('SyncProviderResourcesJob: cost provider not found', [
                'cost_provider_id' => $this->costProviderId,
            ]);

            return;
        }

        if (! $provider->enabled) {
            Log::info('SyncProviderResourcesJob: provider disabled, skipping', [
                'cost_provider_id' => $provider->id,
            ]);

            return;
        }

        if (! $registry->supportsResourceSync($provider->slug)) {
            Log::info('SyncProviderResourcesJob: provider has no resource inventory, skipping', [
                'cost_provider_id' => $provider->id,
                'slug' => $provider->slug->value,
            ]);

            return;
        }

        try {
            $observed = $registry->resourceSyncFor($provider->slug)->syncProjectsAndResources($provider);
        } catch (UnsupportedProviderCapability $e) {
            Log::warning('SyncProviderResourcesJob: unsupported capability', [
                'cost_provider_id' => $provider->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('SyncProviderResourcesJob: completed', [
            'cost_provider_id' => $provider->id,
            'slug' => $provider->slug->value,
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
        Log::error('SyncProviderResourcesJob: failed permanently', [
            'cost_provider_id' => $this->costProviderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
