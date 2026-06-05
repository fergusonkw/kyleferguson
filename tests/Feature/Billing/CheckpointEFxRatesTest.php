<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\FxRateSource;
use App\Jobs\Billing\FetchFxRateJob;
use App\Models\Billing\FxRate;
use App\Services\Billing\FxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class CheckpointEFxRatesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-06';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_fx_rate_service_fetches_and_caches_boc_rate(): void
    {
        $this->fakeBocApi(1.3680);

        $service = app(FxRateService::class);
        $rate = $service->getRate('USD', 'CAD', self::PERIOD);

        $this->assertEqualsWithDelta(1.3680, $rate, 0.0001);
        $this->assertSame(1, FxRate::count());

        $cached = FxRate::first();
        $this->assertSame('USD', $cached->currency_from);
        $this->assertSame('CAD', $cached->currency_to);
        $this->assertSame(self::PERIOD, $cached->period);
        $this->assertSame(FxRateSource::BankOfCanada, $cached->source);
    }

    public function test_fx_rate_service_returns_cached_rate_without_api_call(): void
    {
        $this->fakeBocApi(1.3680);
        $service = app(FxRateService::class);
        $service->getRate('USD', 'CAD', self::PERIOD);

        Http::preventStrayRequests();

        $rateAgain = $service->getRate('USD', 'CAD', self::PERIOD);

        $this->assertEqualsWithDelta(1.3680, $rateAgain, 0.0001);
        $this->assertSame(1, FxRate::count());
    }

    public function test_fx_rate_service_returns_one_for_same_currency(): void
    {
        $service = app(FxRateService::class);
        $rate = $service->getRate('USD', 'USD', self::PERIOD);

        $this->assertSame(1.0, $rate);
        $this->assertSame(0, FxRate::count());
    }

    public function test_fx_rate_service_throws_when_boc_returns_no_observations(): void
    {
        Http::fake([
            'www.bankofcanada.ca/*' => Http::response(['observations' => [], 'seriesDetail' => []], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no observations/i');

        app(FxRateService::class)->getRate('USD', 'CAD', self::PERIOD);
    }

    public function test_fx_rate_service_throws_when_api_fails(): void
    {
        Http::fake([
            'www.bankofcanada.ca/*' => Http::response(['message' => 'internal error'], 500),
        ]);

        $this->expectException(RuntimeException::class);

        app(FxRateService::class)->getRate('USD', 'CAD', self::PERIOD);
    }

    public function test_fx_rate_prefetch_is_idempotent(): void
    {
        $this->fakeBocApi(1.3680);
        $service = app(FxRateService::class);

        $r1 = $service->prefetch('USD', 'CAD', self::PERIOD);
        $r2 = $service->prefetch('USD', 'CAD', self::PERIOD);

        $this->assertSame($r1, $r2);
        $this->assertSame(1, FxRate::count());
    }

    public function test_fetch_fx_rate_job_can_be_dispatched(): void
    {
        Bus::fake();

        FetchFxRateJob::dispatch('USD', 'CAD', self::PERIOD);

        Bus::assertDispatched(
            FetchFxRateJob::class,
            fn (FetchFxRateJob $job): bool => $job->currencyFrom === 'USD'
                && $job->currencyTo === 'CAD'
                && $job->period === self::PERIOD,
        );
    }

    private function fakeBocApi(float $rate): void
    {
        Http::fake([
            'www.bankofcanada.ca/*' => Http::response([
                'observations' => [
                    [
                        'FXUSDCAD' => ['v' => (string) $rate],
                        'd' => self::PERIOD.'-01',
                    ],
                ],
            ], 200),
        ]);
    }
}
