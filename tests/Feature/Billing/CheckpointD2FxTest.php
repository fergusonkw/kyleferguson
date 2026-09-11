<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\FxRateSource;
use App\Exceptions\Billing\FxRateUnavailableException;
use App\Models\Billing\FxRate;
use App\Services\Billing\FxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FX resolution: Bank of Canada monthly averages, caching, crossing, and the
 * refusal to guess when a rate cannot be resolved.
 */
final class CheckpointD2FxTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    /** Mean of the four observations in the USD/CAD fixture. */
    private const USDCAD_AVERAGE = 1.375;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_identity_pair_short_circuits_without_a_request(): void
    {
        Http::fake();

        $rate = app(FxRateService::class)->rateFor('USD', 'USD', self::PERIOD);

        $this->assertSame(1.0, $rate);
        $this->assertNull(app(FxRateService::class)->rateRecordFor('USD', 'USD', self::PERIOD));
        $this->assertSame(0, FxRate::count());
        Http::assertNothingSent();
    }

    public function test_identity_pair_is_case_insensitive(): void
    {
        Http::fake();

        $this->assertSame(1.0, app(FxRateService::class)->rateFor('usd', 'USD', self::PERIOD));
        Http::assertNothingSent();
    }

    public function test_usd_to_cad_averages_the_monthly_observations(): void
    {
        $this->fakeUsdCadSeries();

        $rate = app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);

        $this->assertSame(self::USDCAD_AVERAGE, $rate);
    }

    public function test_resolved_rate_is_cached_and_not_refetched(): void
    {
        $this->fakeUsdCadSeries();
        $service = app(FxRateService::class);

        $service->rateFor('USD', 'CAD', self::PERIOD);
        $service->rateFor('USD', 'CAD', self::PERIOD);

        Http::assertSentCount(1);
        $this->assertSame(1, FxRate::count());

        $stored = FxRate::query()->firstOrFail();
        $this->assertSame('USD', $stored->currency_from);
        $this->assertSame('CAD', $stored->currency_to);
        $this->assertSame(self::PERIOD, $stored->period);
        $this->assertSame(FxRateSource::BankOfCanada, $stored->source);
        $this->assertNotNull($stored->fetched_at);
    }

    public function test_a_pre_seeded_rate_is_used_without_any_request(): void
    {
        Http::fake();
        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(1.4)->create();

        $rate = app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);

        $this->assertSame(1.4, $rate);
        Http::assertNothingSent();
    }

    public function test_cad_to_usd_inverts_the_published_series(): void
    {
        $this->fakeUsdCadSeries();

        $rate = app(FxRateService::class)->rateFor('CAD', 'USD', self::PERIOD);

        $this->assertEqualsWithDelta(1 / self::USDCAD_AVERAGE, $rate, 0.00000001);
    }

    public function test_non_cad_pair_is_crossed_through_cad(): void
    {
        Http::fake([
            '*/observations/FXUSDCAD/json*' => Http::response($this->jsonFixture('bankofcanada/fxusdcad.json')),
            '*/observations/FXEURCAD/json*' => Http::response($this->seriesResponse('FXEURCAD', ['1.5000', '1.5000'])),
        ]);

        $rate = app(FxRateService::class)->rateFor('USD', 'EUR', self::PERIOD);

        $this->assertEqualsWithDelta(self::USDCAD_AVERAGE / 1.5, $rate, 0.00000001);
    }

    public function test_rates_are_stored_per_period(): void
    {
        Http::fake([
            '*/observations/FXUSDCAD/json*' => Http::response($this->jsonFixture('bankofcanada/fxusdcad.json')),
        ]);
        $service = app(FxRateService::class);

        $service->rateFor('USD', 'CAD', '2026-07');
        $service->rateFor('USD', 'CAD', '2026-08');

        $this->assertSame(2, FxRate::count());
        Http::assertSentCount(2);
    }

    public function test_period_bounds_are_sent_to_the_bank_of_canada(): void
    {
        $this->fakeUsdCadSeries();

        app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['start_date'] ?? null) === '2026-08-01'
                && ($query['end_date'] ?? null) === '2026-08-31';
        });
    }

    public function test_empty_observations_throw_rather_than_guess(): void
    {
        Http::fake([
            '*/observations/*' => Http::response(['observations' => []]),
        ]);

        $this->expectException(FxRateUnavailableException::class);
        $this->expectExceptionMessageMatches('/no observations/');

        app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);
    }

    public function test_non_numeric_observations_are_skipped(): void
    {
        Http::fake([
            '*/observations/FXUSDCAD/json*' => Http::response($this->seriesResponse('FXUSDCAD', ['', '1.4000', '1.2000'])),
        ]);

        $rate = app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);

        $this->assertSame(1.3, $rate);
    }

    public function test_upstream_failure_throws_and_caches_nothing(): void
    {
        Http::fake([
            '*/observations/*' => Http::response(['message' => 'service unavailable'], 503),
        ]);

        try {
            app(FxRateService::class)->rateFor('USD', 'CAD', self::PERIOD);
            $this->fail('Expected FxRateUnavailableException');
        } catch (FxRateUnavailableException $e) {
            $this->assertStringContainsString('USD→CAD', $e->getMessage());
        }

        $this->assertSame(0, FxRate::count());
    }

    public function test_malformed_period_throws(): void
    {
        Http::fake();

        $this->expectException(FxRateUnavailableException::class);
        $this->expectExceptionMessageMatches('/YYYY-MM/');

        app(FxRateService::class)->rateFor('USD', 'CAD', 'August 2026');
    }

    public function test_convert_applies_the_period_rate(): void
    {
        $this->fakeUsdCadSeries();

        $converted = app(FxRateService::class)->convert(100.0, 'USD', 'CAD', self::PERIOD);

        $this->assertSame(137.5, $converted);
    }

    public function test_convert_is_a_no_op_for_identity_pairs(): void
    {
        Http::fake();

        $this->assertSame(42.5, app(FxRateService::class)->convert(42.5, 'USD', 'USD', self::PERIOD));
        Http::assertNothingSent();
    }

    private function fakeUsdCadSeries(): void
    {
        Http::fake([
            '*/observations/FXUSDCAD/json*' => Http::response($this->jsonFixture('bankofcanada/fxusdcad.json')),
        ]);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function seriesResponse(string $series, array $values): array
    {
        $observations = [];
        foreach ($values as $index => $value) {
            $observations[] = [
                'd' => sprintf('2026-08-%02d', $index + 1),
                $series => ['v' => $value],
            ];
        }

        return ['observations' => $observations];
    }
}
