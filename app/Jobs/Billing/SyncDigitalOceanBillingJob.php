<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\CostProvider;
use App\Services\Billing\CostAttributor;
use App\Services\Billing\DigitalOcean\BillingSync;
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
 * Pulls billing data for one cost provider and period, derives cost line items,
 * then runs attribution to link items to local projects. Idempotent: unchanged
 * remote payloads are detected by content hash and skipped.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
#[MaxExceptions(3)]
final class SyncDigitalOceanBillingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $costProviderId,
        public readonly string $period,
    ) {}

    public function handle(BillingSync $billingSync, CostAttributor $attributor): void
    {
        $provider = CostProvider::find($this->costProviderId);

        if ($provider === null) {
            Log::warning('SyncDigitalOceanBillingJob: cost provider not found', [
                'cost_provider_id' => $this->costProviderId,
            ]);

            return;
        }

        if (! $provider->enabled) {
            Log::info('SyncDigitalOceanBillingJob: provider disabled, skipping', [
                'cost_provider_id' => $provider->id,
            ]);

            return;
        }

        $written = $billingSync->syncBilling($provider, $this->period);
        $attributed = $attributor->attributeForProvider($provider, $this->period);

        Log::info('SyncDigitalOceanBillingJob: completed', [
            'cost_provider_id' => $provider->id,
            'period' => $this->period,
            'items_written' => $written,
            'items_attributed' => $attributed,
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
        Log::error('SyncDigitalOceanBillingJob: failed permanently', [
            'cost_provider_id' => $this->costProviderId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
