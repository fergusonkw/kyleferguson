<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Exceptions\Billing\ProviderConfigurationException;
use App\Exceptions\Billing\ProviderRejectedRequest;
use App\Models\Billing\CostProvider;
use App\Services\Billing\BillingPeriod;
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
 * Ingests one provider's costs for one period. Idempotent — re-running an
 * unchanged period neither duplicates line items nor appends payload history.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
#[MaxExceptions(3)]
final class SyncProviderBillingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $costProviderId,
        public readonly string $period,
    ) {}

    public function handle(ProviderAdapterRegistry $registry): void
    {
        if (! BillingPeriod::isValid($this->period)) {
            Log::error('SyncProviderBillingJob: invalid period, skipping', [
                'cost_provider_id' => $this->costProviderId,
                'period' => $this->period,
            ]);

            return;
        }

        $provider = CostProvider::find($this->costProviderId);

        if ($provider === null) {
            Log::warning('SyncProviderBillingJob: cost provider not found', [
                'cost_provider_id' => $this->costProviderId,
            ]);

            return;
        }

        if (! $provider->enabled) {
            Log::info('SyncProviderBillingJob: provider disabled, skipping', [
                'cost_provider_id' => $provider->id,
            ]);

            return;
        }

        if (! $registry->supportsBillingSync($provider->slug)) {
            Log::info('SyncProviderBillingJob: provider has no billing adapter, skipping', [
                'cost_provider_id' => $provider->id,
                'slug' => $provider->slug->value,
            ]);

            return;
        }

        try {
            $written = $registry->billingSyncFor($provider->slug)->syncBilling($provider, $this->period);
        } catch (ProviderRejectedRequest|ProviderConfigurationException $e) {
            // Neither a bad credential nor missing config gets better by trying
            // again. Retrying would only bury the provider's own explanation
            // under a MaxAttemptsExceededException by the time it lands in
            // failed_jobs, which is exactly the message an operator needs.
            Log::error('SyncProviderBillingJob: provider refused, not retrying', [
                'cost_provider_id' => $provider->id,
                'slug' => $provider->slug->value,
                'period' => $this->period,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        Log::info('SyncProviderBillingJob: completed', [
            'cost_provider_id' => $provider->id,
            'slug' => $provider->slug->value,
            'period' => $this->period,
            'line_items' => $written,
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
        return now()->addHours(2)->toDateTimeImmutable();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncProviderBillingJob: failed permanently', [
            'cost_provider_id' => $this->costProviderId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
