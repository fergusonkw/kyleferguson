<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\CostProvider;
use App\Services\Billing\RestatementDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Diffs the two most recent billing payloads for a provider+period and applies
 * adjustment lines to open draft invoices for affected clients.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
final class DetectBillingRestatementsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $costProviderId,
        public readonly string $period,
    ) {}

    public function handle(RestatementDetector $detector): void
    {
        $provider = CostProvider::find($this->costProviderId);

        if ($provider === null) {
            Log::warning('DetectBillingRestatementsJob: cost provider not found', [
                'cost_provider_id' => $this->costProviderId,
            ]);

            return;
        }

        $adjustments = $detector->detectAndApply($provider, $this->period);

        Log::info('DetectBillingRestatementsJob: completed', [
            'cost_provider_id' => $provider->id,
            'period' => $this->period,
            'adjustments_applied' => $adjustments,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('DetectBillingRestatementsJob: failed permanently', [
            'cost_provider_id' => $this->costProviderId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
