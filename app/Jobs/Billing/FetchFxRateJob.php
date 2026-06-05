<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\FxRateSource;
use App\Services\Billing\FxRateService;
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
 * Fetches and caches the monthly-average FX rate for the given currency pair
 * and period. Safe to re-run; the FxRateService skips the fetch if a rate is
 * already cached for the same period and source.
 */
#[Tries(5)]
#[Backoff([30, 60, 120, 300, 600])]
#[MaxExceptions(5)]
final class FetchFxRateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $currencyFrom,
        public readonly string $currencyTo,
        public readonly string $period,
        public readonly FxRateSource $source = FxRateSource::BankOfCanada,
    ) {}

    public function handle(FxRateService $fxRateService): void
    {
        $rate = $fxRateService->prefetch(
            currencyFrom: $this->currencyFrom,
            currencyTo: $this->currencyTo,
            period: $this->period,
            source: $this->source,
        );

        Log::info('FetchFxRateJob: rate cached', [
            'pair' => "{$this->currencyFrom}/{$this->currencyTo}",
            'period' => $this->period,
            'source' => $this->source->value,
            'rate' => $rate,
        ]);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottlesExceptions(3, 5 * 60)];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return now()->addHours(2)->toDateTimeImmutable();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FetchFxRateJob: failed permanently', [
            'pair' => "{$this->currencyFrom}/{$this->currencyTo}",
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
