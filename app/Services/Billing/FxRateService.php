<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\FxRateSource;
use App\Exceptions\Billing\FxRateUnavailableException;
use App\Models\Billing\FxRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves monthly-average conversion rates, cached in `fx_rates`.
 *
 * The Bank of Canada publishes every pair against CAD (`FXUSDCAD`,
 * `FXEURCAD`, …), so a non-CAD pair is crossed through CAD. Identity pairs
 * short-circuit to 1.0 and are never persisted or fetched.
 */
final class FxRateService
{
    private const BASE_URL = 'https://www.bankofcanada.ca/valet';

    private const SCALE = 8;

    /**
     * Monthly-average rate to multiply a `$from` amount by to get `$to`.
     */
    public function rateFor(string $from, string $to, string $period): float
    {
        $from = mb_strtoupper($from);
        $to = mb_strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        return (float) $this->rateRecordFor($from, $to, $period)->rate;
    }

    /**
     * The cached rate row, fetching and storing it on a cache miss. Returns
     * null for identity pairs, which have no stored rate — callers that
     * snapshot provenance should record `1.0` / `FxRateSource::Internal`.
     */
    public function rateRecordFor(string $from, string $to, string $period): ?FxRate
    {
        $from = mb_strtoupper($from);
        $to = mb_strtoupper($to);

        if ($from === $to) {
            return null;
        }

        $this->periodStart($period, $from, $to);

        $cached = FxRate::query()
            ->where('currency_from', $from)
            ->where('currency_to', $to)
            ->where('period', $period)
            ->first();

        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->computeRate($from, $to, $period);

        return FxRate::query()->firstOrCreate(
            [
                'currency_from' => $from,
                'currency_to' => $to,
                'period' => $period,
                'source' => FxRateSource::BankOfCanada,
            ],
            [
                'rate' => $rate,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * Convert an amount between currencies at the period's average rate.
     */
    public function convert(float $amount, string $from, string $to, string $period): float
    {
        return round($amount * $this->rateFor($from, $to, $period), 4);
    }

    private function computeRate(string $from, string $to, string $period): string
    {
        if ($to === 'CAD') {
            return $this->monthlyAverage($from, $period, $from, $to);
        }

        if ($from === 'CAD') {
            $toPerCad = $this->monthlyAverage($to, $period, $from, $to);

            return $this->divide('1', $toPerCad, $from, $to, $period);
        }

        $fromInCad = $this->monthlyAverage($from, $period, $from, $to);
        $toInCad = $this->monthlyAverage($to, $period, $from, $to);

        return $this->divide($fromInCad, $toInCad, $from, $to, $period);
    }

    /**
     * Mean of the daily observations for `FX{currency}CAD` over the period.
     */
    private function monthlyAverage(string $currency, string $period, string $from, string $to): string
    {
        $series = sprintf('FX%sCAD', $currency);
        $start = $this->periodStart($period, $from, $to);

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(30)
                ->retry(3, 250, fn (Throwable $e): bool => $e instanceof ConnectionException)
                ->get("/observations/{$series}/json", [
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->endOfMonth()->toDateString(),
                ]);

            $response->throw();
        } catch (ConnectionException|RequestException $e) {
            throw FxRateUnavailableException::forPair(
                $from, $to, $period, "Bank of Canada request for {$series} failed", $e,
            );
        }

        $values = [];
        foreach ($response->json('observations') ?? [] as $observation) {
            $value = $observation[$series]['v'] ?? null;

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        if ($values === []) {
            throw FxRateUnavailableException::forPair(
                $from, $to, $period, "Bank of Canada returned no observations for {$series}",
            );
        }

        return number_format(array_sum($values) / count($values), self::SCALE, '.', '');
    }

    private function divide(string $numerator, string $denominator, string $from, string $to, string $period): string
    {
        if ((float) $denominator === 0.0) {
            throw FxRateUnavailableException::forPair($from, $to, $period, 'the crossing rate averaged zero');
        }

        return bcdiv($numerator, $denominator, self::SCALE);
    }

    private function periodStart(string $period, string $from, string $to): Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw FxRateUnavailableException::forPair($from, $to, $period, 'the period is not in YYYY-MM format');
        }

        return Carbon::createFromFormat('Y-m', $period)->startOfMonth();
    }
}
