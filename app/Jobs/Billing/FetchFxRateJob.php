<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Exceptions\Billing\FxRateUnavailableException;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\FxRateService;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warms the FX cache for a currency pair and period so invoice generation is
 * not the first thing to discover the rate is unavailable.
 */
#[Tries(3)]
#[Backoff([300, 900, 3600])]
final class FetchFxRateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $currencyFrom,
        public readonly string $currencyTo,
        public readonly string $period,
    ) {}

    public function handle(FxRateService $rates): void
    {
        if (! BillingPeriod::isValid($this->period)) {
            Log::error('FetchFxRateJob: invalid period, skipping', [
                'period' => $this->period,
            ]);

            return;
        }

        try {
            $rate = $rates->rateFor($this->currencyFrom, $this->currencyTo, $this->period);
        } catch (FxRateUnavailableException $e) {
            Log::warning('FetchFxRateJob: rate unavailable', [
                'pair' => "{$this->currencyFrom}->{$this->currencyTo}",
                'period' => $this->period,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('FetchFxRateJob: cached', [
            'pair' => "{$this->currencyFrom}->{$this->currencyTo}",
            'period' => $this->period,
            'rate' => $rate,
        ]);
    }

    public function retryUntil(): DateTimeImmutable
    {
        return now()->addDay()->toDateTimeImmutable();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FetchFxRateJob: failed permanently', [
            'pair' => "{$this->currencyFrom}->{$this->currencyTo}",
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
