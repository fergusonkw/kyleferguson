<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\Business;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CostAttributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves which project each ingested cost belongs to, for one business and
 * period. Runs after the day's billing syncs so newly ingested lines and newly
 * corrected assignments both land.
 */
#[Tries(3)]
#[Backoff([30, 120, 300])]
final class AttributeCostsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $period,
    ) {}

    public function handle(CostAttributor $attributor): void
    {
        if (! BillingPeriod::isValid($this->period)) {
            Log::error('AttributeCostsJob: invalid period, skipping', [
                'business_id' => $this->businessId,
                'period' => $this->period,
            ]);

            return;
        }

        if (! Business::query()->whereKey($this->businessId)->exists()) {
            Log::warning('AttributeCostsJob: business not found', [
                'business_id' => $this->businessId,
            ]);

            return;
        }

        $result = $attributor->attribute($this->businessId, $this->period);

        Log::info('AttributeCostsJob: completed', [
            'business_id' => $this->businessId,
            'period' => $this->period,
        ] + $result->toArray());
    }

    public function failed(Throwable $exception): void
    {
        Log::error('AttributeCostsJob: failed permanently', [
            'business_id' => $this->businessId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
