<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Services\Billing\Smtp2go\BillingSync;
use App\Services\Billing\Smtp2go\Dto\Smtp2goCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMTP2GO usage keeps moving after the month it belongs to has closed.
 *
 * An account's cycle is anchored to its signup day, not the 1st, so the cycle
 * that began in August runs into September. The daily sync only targets the
 * open periods, which left a closed (and usually invoiced) month frozen at
 * whatever usage it had on its last scheduled sync — and a sync after the
 * cycle rolled over would replace it with the next cycle's near-zero count.
 */
final class CheckpointF9Smtp2goUsageRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $cycleResponse = [];

    private bool $cycleStubbed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_mid_month_cycle_covers_both_periods_it_straddles(): void
    {
        $cycle = Smtp2goCycle::fromApiPayload($this->cycle('2026-08-15', '2026-09-14', 100));

        $this->assertSame(['2026-08', '2026-09'], $cycle->coveredPeriods());
    }

    public function test_a_calendar_aligned_cycle_covers_only_its_own_period(): void
    {
        $cycle = Smtp2goCycle::fromApiPayload($this->cycle('2026-08-01', '2026-08-31', 100));

        $this->assertSame(['2026-08'], $cycle->coveredPeriods());
    }

    public function test_a_cycle_without_dates_covers_nothing(): void
    {
        $cycle = Smtp2goCycle::fromApiPayload(['cycle_used' => 5]);

        $this->assertSame([], $cycle->coveredPeriods());
    }

    public function test_syncing_the_current_period_refreshes_usage_on_an_invoiced_previous_period(): void
    {
        [$client, $project, $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-15', '2026-09-14', 1200);
        $sync->syncBilling($provider, '2026-08');

        $august = CostLineItem::query()->forPeriod('2026-08')->firstOrFail();
        $this->invoiceAndSend($client, $project, $august);

        $this->fakeCycle('2026-08-15', '2026-09-14', 7400);
        $sync->syncBilling($provider, '2026-09');

        $august->refresh();
        $this->assertSame(7400, $august->metadata['cycle_used']);
        $this->assertTrue($august->metadata['cycle_covers_period']);
        $this->assertStringContainsString('7,400 of 10,000 emails', $august->description);
        $this->assertSame(7400, $august->sourcePayload->raw_payload['cycle_used']);
    }

    public function test_refreshing_a_closed_periods_usage_leaves_what_it_was_charged_alone(): void
    {
        [, , $provider] = $this->clientWithAccount(fee: 15.0);
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-15', '2026-09-14', 1200);
        $sync->syncBilling($provider, '2026-08');
        $derivedAt = CostLineItem::query()->forPeriod('2026-08')->firstOrFail()->derived_at;

        // The plan fee changes in September; August was billed at the old one.
        $provider->update(['config' => ['monthly_fee' => '25.00', 'fee_currency' => 'USD', 'region' => 'global']]);

        $this->travel(1)->days();
        $this->fakeCycle('2026-08-15', '2026-09-14', 7400);
        $sync->syncBilling($provider, '2026-09');

        $august = CostLineItem::query()->forPeriod('2026-08')->firstOrFail();
        $this->assertSame('15.0000', $august->usd_amount);
        $this->assertSame('15.0000', $august->source_amount);
        $this->assertTrue($august->derived_at->equalTo($derivedAt));
        $this->assertSame('25.0000', CostLineItem::query()->forPeriod('2026-09')->firstOrFail()->usd_amount);
    }

    public function test_the_invoice_is_not_touched_when_its_periods_usage_refreshes(): void
    {
        [$client, $project, $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-15', '2026-09-14', 1200);
        $sync->syncBilling($provider, '2026-08');
        $invoice = $this->invoiceAndSend($client, $project, CostLineItem::query()->forPeriod('2026-08')->firstOrFail());
        $before = $invoice->lines()->get(['id', 'amount', 'description', 'label'])->toArray();

        $this->fakeCycle('2026-08-15', '2026-09-14', 7400);
        $sync->syncBilling($provider, '2026-09');

        $this->assertSame($before, $invoice->lines()->get(['id', 'amount', 'description', 'label'])->toArray());
        $this->assertSame('15.00', $invoice->fresh()->total);
    }

    public function test_a_period_the_cycle_does_not_overlap_is_left_alone(): void
    {
        [, , $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-07-01', '2026-07-31', 3000);
        $sync->syncBilling($provider, '2026-07');

        $this->fakeCycle('2026-08-15', '2026-09-14', 7400);
        $sync->syncBilling($provider, '2026-09');

        $this->assertSame(3000, CostLineItem::query()->forPeriod('2026-07')->firstOrFail()->metadata['cycle_used']);
    }

    public function test_a_period_does_not_have_its_usage_replaced_by_the_next_cycle(): void
    {
        [, , $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-01', '2026-08-31', 9100);
        $sync->syncBilling($provider, '2026-08');

        // Within the grace days August is still synced directly, but by then
        // the account has rolled into September's cycle.
        $this->fakeCycle('2026-09-01', '2026-09-30', 40);
        $sync->syncBilling($provider, '2026-08');

        $august = CostLineItem::query()->forPeriod('2026-08')->firstOrFail();
        $this->assertSame(9100, $august->metadata['cycle_used']);
        $this->assertTrue($august->metadata['cycle_covers_period']);
        $this->assertSame('2026-08-01', $august->sourcePayload->source_key);
    }

    public function test_a_direct_sync_still_refreshes_what_the_period_is_charged_when_its_usage_is_kept(): void
    {
        [, , $provider] = $this->clientWithAccount(fee: 15.0);
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-01', '2026-08-31', 9100);
        $sync->syncBilling($provider, '2026-08');

        $provider->update(['config' => ['monthly_fee' => '18.00', 'fee_currency' => 'USD', 'region' => 'global']]);
        $this->fakeCycle('2026-09-01', '2026-09-30', 40);
        $sync->syncBilling($provider, '2026-08');

        $august = CostLineItem::query()->forPeriod('2026-08')->firstOrFail();
        $this->assertSame('18.0000', $august->usd_amount);
        $this->assertSame(9100, $august->metadata['cycle_used']);
    }

    public function test_a_backfilled_period_with_no_covering_usage_keeps_taking_the_latest(): void
    {
        [, , $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-01', '2026-08-31', 500);
        $sync->syncBilling($provider, '2026-03');

        $this->fakeCycle('2026-09-01', '2026-09-30', 40);
        $sync->syncBilling($provider, '2026-03');

        $march = CostLineItem::query()->forPeriod('2026-03')->firstOrFail();
        $this->assertSame(40, $march->metadata['cycle_used']);
        $this->assertFalse($march->metadata['cycle_covers_period']);
    }

    public function test_refreshing_another_period_keeps_one_payload_per_period_and_cycle(): void
    {
        [, , $provider] = $this->clientWithAccount();
        $sync = app(BillingSync::class);

        $this->fakeCycle('2026-08-15', '2026-09-14', 1200);
        $sync->syncBilling($provider, '2026-08');
        $sync->syncBilling($provider, '2026-09');
        $sync->syncBilling($provider, '2026-09');

        $this->assertSame(2, ProviderBillingPayload::count());
        $this->assertSame(
            ['2026-08', '2026-09'],
            ProviderBillingPayload::query()->orderBy('period')->pluck('period')->all(),
        );
        $this->assertSame(2, CostLineItem::count());
    }

    /**
     * @return array{0: Client, 1: Project, 2: CostProvider}
     */
    private function clientWithAccount(float $fee = 15.0): array
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create(['billing_currency' => 'USD']);
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->smtp2go($fee)->forClient($client)->create();

        return [$client, $project, $provider];
    }

    private function invoiceAndSend(Client $client, Project $project, CostLineItem $costLine): Invoice
    {
        $invoice = Invoice::factory()
            ->for($client)
            ->for($client->business)
            ->forPeriod($costLine->period)
            ->sent()
            ->withTotal(15.0)
            ->create(['issue_currency' => 'USD']);

        InvoiceLine::factory()
            ->for($invoice)
            ->forProject($project)
            ->ofType(InvoiceLineType::Hosting)
            ->amount(15.0)
            ->create(['source_reference' => $costLine->invoiceSourceReference()]);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function cycle(string $start, string $end, int $used, int $max = 10000): array
    {
        return [
            'cycle_start' => "{$start} 00:00:00+00:00",
            'cycle_end' => "{$end} 23:59:59+00:00",
            'cycle_used' => $used,
            'cycle_remaining' => max(0, $max - $used),
            'cycle_max' => $max,
        ];
    }

    /**
     * Stub the cycle endpoint. Re-calling this replaces the response, which a
     * second `Http::fake()` would not do — the earliest matching stub wins.
     */
    private function fakeCycle(string $start, string $end, int $used): void
    {
        $this->cycleResponse = ['data' => $this->cycle($start, $end, $used)];

        if ($this->cycleStubbed) {
            return;
        }

        $this->cycleStubbed = true;
        Http::fake(fn () => Http::response($this->cycleResponse));
    }
}
