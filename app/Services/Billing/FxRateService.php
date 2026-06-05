<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\FxRateSource;
use App\Models\Billing\FxRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FxRateService
{
    /**
     * Return the monthly-average exchange rate for the given period (YYYY-MM).
     * For a same-currency pair (e.g. USD→USD) always returns 1.0.
     * Caches in the fx_rates table to avoid redundant API calls.
     */
    public function getRate(
        string $currencyFrom,
        string $currencyTo,
        string $period,
        FxRateSource $source = FxRateSource::BankOfCanada,
    ): float {
        if ($currencyFrom === $currencyTo) {
            return 1.0;
        }

        $cached = FxRate::query()
            ->where('currency_from', $currencyFrom)
            ->where('currency_to', $currencyTo)
            ->where('period', $period)
            ->where('source', $source)
            ->first();

        if ($cached !== null) {
            return $cached->rate;
        }

        $rate = $this->fetchFromSource($currencyFrom, $currencyTo, $period, $source);

        FxRate::create([
            'currency_from' => $currencyFrom,
            'currency_to' => $currencyTo,
            'period' => $period,
            'rate' => $rate,
            'source' => $source,
            'fetched_at' => now(),
        ]);

        return $rate;
    }

    /**
     * Pre-fetch and cache the rate for the given period. Safe to call
     * repeatedly — returns the rate regardless of whether it was newly fetched.
     */
    public function prefetch(
        string $currencyFrom,
        string $currencyTo,
        string $period,
        FxRateSource $source = FxRateSource::BankOfCanada,
    ): float {
        return $this->getRate($currencyFrom, $currencyTo, $period, $source);
    }

    private function fetchFromSource(
        string $currencyFrom,
        string $currencyTo,
        string $period,
        FxRateSource $source,
    ): float {
        return match ($source) {
            FxRateSource::BankOfCanada => $this->fetchBankOfCanada($currencyFrom, $currencyTo, $period),
        };
    }

    /**
     * Bank of Canada Valet API: monthly-average series.
     * Series name convention: FX{FROM}{TO} e.g. FXUSDCAD.
     */
    private function fetchBankOfCanada(string $currencyFrom, string $currencyTo, string $period): float
    {
        $series = 'FX'.strtoupper($currencyFrom).strtoupper($currencyTo);
        $startDate = $period.'-01';
        $endDate = $period.'-28'; // Safe lower bound — BofC returns the latest available

        $url = FxRateSource::BankOfCanada->apiBaseUrl()."/observations/{$series}/json";

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->retry(3, 500, fn ($e) => $e instanceof ConnectionException)
                ->get($url, [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'order_dir' => 'desc',
                    'limit' => 1,
                ]);

            $response->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException(
                "FxRateService: could not reach Bank of Canada API for {$series}/{$period}: {$e->getMessage()}",
                previous: $e,
            );
        }

        $observations = $response->json('observations') ?? [];

        if ($observations === []) {
            throw new RuntimeException(
                "FxRateService: no observations returned for {$series} in period {$period}.",
            );
        }

        $value = $observations[0][$series]['v'] ?? null;

        if ($value === null) {
            throw new RuntimeException(
                "FxRateService: unexpected response shape for {$series} in period {$period}.",
            );
        }

        return (float) $value;
    }
}
