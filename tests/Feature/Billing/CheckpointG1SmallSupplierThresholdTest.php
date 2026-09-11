<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\ThresholdLevel;
use App\Enums\Billing\ThresholdTest;
use App\Jobs\Billing\SmallSupplierThresholdAlertJob;
use App\Mail\Billing\SmallSupplierThresholdAlert;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\LegalEntity;
use App\Services\Billing\Dto\ThresholdAssessment;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\SmallSupplierThreshold;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The GST/HST small-supplier threshold.
 *
 * GST/HST belongs to the legal person, not the trade name, so every business
 * under an entity — and every associated entity — counts toward one $30,000.
 * It trips on a single calendar quarter or on four consecutive ones. "Today"
 * is mid-Q3 2026 throughout, so the four-quarter window is Q4 2025 – Q3 2026.
 */
final class CheckpointG1SmallSupplierThresholdTest extends TestCase
{
    use RefreshDatabase;

    private LegalEntity $entity;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-09-15 09:00:00'));

        $this->entity = LegalEntity::factory()->create(['name' => 'Kyle Ferguson']);
        $this->business = Business::factory()->forLegalEntity($this->entity)->create(['name' => 'Kyle Ferguson']);
    }

    public function test_supplies_are_summed_by_calendar_quarter_across_the_four_quarter_window(): void
    {
        $this->supply('2025-11-10', '1000.00');
        $this->supply('2026-02-01', '2000.00');
        $this->supply('2026-06-30', '3000.00');
        $this->supply('2026-07-01', '4000.00');
        $this->supply('2026-09-15', '500.00');

        $assessment = $this->assess();

        $this->assertSame(
            ['Q4 2025' => '1000.00', 'Q1 2026' => '2000.00', 'Q2 2026' => '3000.00', 'Q3 2026' => '4500.00'],
            collect($assessment->quarters)->pluck('total', 'label')->all(),
        );
        $this->assertSame('10500.00', $assessment->fourQuarterTotal);
        $this->assertSame('4500.00', $assessment->currentQuarterTotal);
        $this->assertSame('19500.00', $assessment->headroom());
        $this->assertTrue($assessment->quarters[3]['current']);
    }

    public function test_a_supply_before_the_window_does_not_count(): void
    {
        $this->supply('2025-09-30', '9000.00');

        $this->assertSame('0.00', $this->assess()->fourQuarterTotal);
    }

    public function test_every_business_under_the_entity_counts_toward_one_threshold(): void
    {
        $trackerPull = Business::factory()->forLegalEntity($this->entity)->create(['name' => 'Tracker Pull']);

        $this->supply('2026-08-01', '1000.00');
        $this->supply('2026-08-02', '2500.00', $trackerPull);

        $assessment = $this->assess();

        $this->assertSame('3500.00', $assessment->fourQuarterTotal);
        $this->assertSame(['Kyle Ferguson', 'Tracker Pull'], $assessment->businessNames);
    }

    public function test_an_associated_entitys_supplies_count_toward_both_thresholds(): void
    {
        $corporation = LegalEntity::factory()->corporation()->create(['name' => 'Tracker Pull Inc.']);
        $corporationBusiness = Business::factory()->forLegalEntity($corporation)->create(['name' => 'Tracker Pull']);
        $this->entity->syncAssociates([$corporation->id]);

        $this->supply('2026-08-01', '1000.00');
        $this->supply('2026-08-02', '2500.00', $corporationBusiness);

        $mine = $this->assess();
        $theirs = app(SmallSupplierThreshold::class)->assess($corporation->fresh());

        $this->assertSame('3500.00', $mine->fourQuarterTotal);
        $this->assertSame('3500.00', $theirs->fourQuarterTotal);
        $this->assertTrue($mine->includesAssociates());
        $this->assertSame(['Tracker Pull Inc.', 'Kyle Ferguson'], $theirs->entityNames);
    }

    public function test_an_unrelated_entitys_supplies_are_not_counted(): void
    {
        $stranger = Business::factory()->create();

        $this->supply('2026-08-01', '1000.00');
        $this->supply('2026-08-02', '25000.00', $stranger);

        $this->assertSame('1000.00', $this->assess()->fourQuarterTotal);
    }

    public function test_drafts_and_voided_invoices_are_not_supplies(): void
    {
        $this->supply('2026-08-01', '1000.00');
        $this->supply('2026-08-02', '5000.00', status: InvoiceStatus::Void);
        Invoice::factory()->for($this->business)->create([
            'status' => InvoiceStatus::Draft,
            'supply_value_cad' => '7000.00',
        ]);

        $this->assertSame('1000.00', $this->assess()->fourQuarterTotal);
    }

    public function test_an_approved_invoice_counts_before_it_is_sent(): void
    {
        $this->supply('2026-08-01', '1000.00', status: InvoiceStatus::Approved);
        $this->supply('2026-08-02', '2000.00', status: InvoiceStatus::Paid);
        $this->supply('2026-08-03', '3000.00', status: InvoiceStatus::PartiallyPaid);

        $this->assertSame('6000.00', $this->assess()->fourQuarterTotal);
    }

    public function test_well_below_the_warning_level_is_clear(): void
    {
        $this->supply('2026-08-01', '23999.99');

        $assessment = $this->assess();

        $this->assertSame(ThresholdLevel::Clear, $assessment->level);
        $this->assertNull($assessment->exceededBy);
        $this->assertFalse($assessment->needsAttention());
    }

    public function test_reaching_the_entitys_warning_percentage_is_approaching(): void
    {
        $this->supply('2025-12-01', '12000.00');
        $this->supply('2026-04-01', '12000.00');

        $this->assertSame(ThresholdLevel::Approaching, $this->assess()->level);
        $this->assertSame(80.0, $this->assess()->percentUsed());
    }

    public function test_the_warning_percentage_is_configurable_per_entity(): void
    {
        $this->entity->update(['threshold_warning_percent' => 50]);
        $this->supply('2026-04-01', '15000.00');

        $assessment = $this->assess();

        $this->assertSame(ThresholdLevel::Approaching, $assessment->level);
        $this->assertSame('15000.00', $assessment->warningAmount());
    }

    public function test_exactly_the_threshold_has_not_passed_it(): void
    {
        $this->supply('2025-12-01', '15000.00');
        $this->supply('2026-04-01', '15000.00');

        $this->assertSame(ThresholdLevel::Approaching, $this->assess()->level);
    }

    public function test_passing_the_threshold_over_four_quarters_is_exceeded(): void
    {
        $this->supply('2025-12-01', '15000.00');
        $this->supply('2026-04-01', '15000.01');

        $assessment = $this->assess();

        $this->assertSame(ThresholdLevel::Exceeded, $assessment->level);
        $this->assertSame(ThresholdTest::FourQuarters, $assessment->exceededBy);
        $this->assertSame('-0.01', $assessment->headroom());
    }

    public function test_passing_the_threshold_in_a_single_quarter_is_exceeded_on_that_test(): void
    {
        $this->supply('2026-07-10', '20000.00');
        $this->supply('2026-09-01', '10000.01');

        $assessment = $this->assess();

        $this->assertSame(ThresholdLevel::Exceeded, $assessment->level);
        $this->assertSame(ThresholdTest::SingleQuarter, $assessment->exceededBy);
    }

    public function test_a_window_that_tripped_last_quarter_still_counts_once_the_quarter_rolls_over(): void
    {
        // Q3 2025 to Q2 2026 passed $30,000; Q3 2025 has since left the
        // current window, but the entity stopped being a small supplier then.
        $this->supply('2025-08-01', '20000.00');
        $this->supply('2026-05-01', '10000.01');

        $assessment = $this->assess();

        $this->assertSame('10000.01', $assessment->fourQuarterTotal);
        $this->assertSame('30000.01', $assessment->previousFourQuarterTotal);
        $this->assertSame(ThresholdLevel::Exceeded, $assessment->level);
        $this->assertSame(ThresholdTest::FourQuarters, $assessment->exceededBy);
    }

    public function test_a_registered_entity_is_reported_as_registered(): void
    {
        $this->entity->update(['tax_registered_from' => '2026-07-01']);
        $this->supply('2026-08-01', '40000.00');

        $assessment = $this->assess();

        $this->assertSame(ThresholdLevel::Registered, $assessment->level);
        $this->assertFalse($assessment->needsAttention());
    }

    public function test_a_registration_date_still_to_come_does_not_count_yet(): void
    {
        $this->entity->update(['tax_registered_from' => '2026-10-01']);
        $this->supply('2026-08-01', '40000.00');

        $this->assertSame(ThresholdLevel::Exceeded, $this->assess()->level);
    }

    public function test_invoices_not_yet_valued_are_reported_rather_than_silently_skipped(): void
    {
        $this->supply('2026-08-01', '1000.00');
        $this->supply('2026-08-02', null);

        $assessment = $this->assess();

        $this->assertSame('1000.00', $assessment->fourQuarterTotal);
        $this->assertSame(1, $assessment->uncountedInvoiceCount);
        $this->assertTrue($assessment->needsAttention());
    }

    public function test_approving_a_cad_invoice_records_what_was_supplied(): void
    {
        $invoice = $this->draftWithLines('CAD', [
            [InvoiceLineType::Manual, '1000.00'],
            [InvoiceLineType::Recurring, '250.00'],
            [InvoiceLineType::Discount, '-100.00'],
            [InvoiceLineType::Credit, '-50.00'],
        ]);

        app(InvoiceApprover::class)->approve($invoice);

        // The credit settles an earlier overpayment rather than reducing the
        // supply, so it is left out; the discount does reduce it.
        $this->assertSame('1150.00', $invoice->fresh()->supply_value_cad);
    }

    public function test_display_only_sub_items_are_not_counted_twice(): void
    {
        $invoice = $this->draftWithLines('CAD', [[InvoiceLineType::Hosting, '300.00']]);
        $parent = $invoice->lines()->firstOrFail();
        InvoiceLine::factory()->subItemOf($parent)->amount(200.0)->create();

        app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame('300.00', $invoice->fresh()->supply_value_cad);
    }

    public function test_approving_a_usd_invoice_converts_it_at_the_periods_rate(): void
    {
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->withRate(1.35)->create();
        $invoice = $this->draftWithLines('USD', [[InvoiceLineType::Manual, '1000.00']], '2026-08');

        app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame('1350.00', $invoice->fresh()->supply_value_cad);
    }

    public function test_an_unavailable_rate_does_not_block_approval_and_is_filled_in_later(): void
    {
        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);
        $invoice = $this->draftWithLines('USD', [[InvoiceLineType::Manual, '1000.00']], '2026-08');

        app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->supply_value_cad);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->withRate(1.40)->create();
        Mail::fake();
        (new SmallSupplierThresholdAlertJob($this->entity->id))->handle(app(SmallSupplierThreshold::class));

        $this->assertSame('1400.00', $invoice->fresh()->supply_value_cad);
    }

    public function test_the_alert_is_sent_once_on_reaching_the_warning_level(): void
    {
        Mail::fake();
        $this->supply('2026-08-01', '25000.00');

        $this->runAlertJob();
        $this->runAlertJob();

        Mail::assertSent(SmallSupplierThresholdAlert::class, 1);
        $this->assertSame(ThresholdLevel::Approaching, $this->entity->fresh()->threshold_alert_level);
        $this->assertNotNull($this->entity->fresh()->threshold_alerted_at);
    }

    public function test_the_alert_is_sent_again_when_the_threshold_is_passed(): void
    {
        Mail::fake();
        $this->supply('2026-08-01', '25000.00');
        $this->runAlertJob();

        $this->supply('2026-09-10', '6000.00');
        $this->runAlertJob();

        Mail::assertSent(SmallSupplierThresholdAlert::class, 2);
        Mail::assertSent(
            SmallSupplierThresholdAlert::class,
            fn (SmallSupplierThresholdAlert $mail): bool => $mail->assessment->level === ThresholdLevel::Exceeded,
        );
    }

    public function test_a_level_that_falls_back_rearms_the_alert(): void
    {
        Mail::fake();
        $this->supply('2025-10-15', '25000.00');
        $this->runAlertJob();

        // Q4 2025 leaves the window when Q4 2026 begins.
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $this->runAlertJob();
        $this->assertSame(ThresholdLevel::Clear, $this->entity->fresh()->threshold_alert_level);

        $this->supply('2026-10-02', '25000.00');
        $this->runAlertJob();

        Mail::assertSent(SmallSupplierThresholdAlert::class, 2);
    }

    public function test_no_alert_while_below_the_warning_level(): void
    {
        Mail::fake();
        $this->supply('2026-08-01', '1000.00');

        $this->runAlertJob();

        Mail::assertNothingSent();
    }

    public function test_a_registered_entity_is_never_alerted(): void
    {
        Mail::fake();
        $this->entity->update(['tax_registered_from' => '2026-01-01']);
        $this->supply('2026-08-01', '40000.00');

        $this->runAlertJob();

        Mail::assertNothingSent();
    }

    public function test_the_alert_goes_to_each_business_under_the_entity(): void
    {
        Mail::fake();
        $this->business->update(['notification_email' => 'kyle@example.test']);
        Business::factory()->forLegalEntity($this->entity)->create(['notification_email' => 'tp@example.test']);
        $this->supply('2026-08-01', '25000.00');

        $this->runAlertJob();

        Mail::assertSent(
            SmallSupplierThresholdAlert::class,
            fn (SmallSupplierThresholdAlert $mail): bool => $mail->hasTo('kyle@example.test') && $mail->hasTo('tp@example.test'),
        );
    }

    public function test_the_alert_mail_renders_the_quarters_and_the_figure(): void
    {
        $this->supply('2026-08-01', '31000.00');

        $mail = new SmallSupplierThresholdAlert($this->assess());
        $html = $mail->render();

        $this->assertStringContainsString('small-supplier', $html);
        $this->assertStringContainsString('$31,000.00 CAD', $html);
        $this->assertStringContainsString('Q3 2026', $html);
        $mail->assertHasSubject('[Kyle Ferguson] Threshold passed — GST/HST small-supplier threshold');
    }

    public function test_the_daily_schedule_checks_every_legal_entity(): void
    {
        Bus::fake();
        $other = LegalEntity::factory()->create();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'billing:small-supplier-threshold');
        $this->assertNotNull($event);

        $event->run($this->app);

        Bus::assertDispatched(SmallSupplierThresholdAlertJob::class, fn ($job): bool => $job->legalEntityId === $this->entity->id);
        Bus::assertDispatched(SmallSupplierThresholdAlertJob::class, fn ($job): bool => $job->legalEntityId === $other->id);
    }

    private function assess(): ThresholdAssessment
    {
        return app(SmallSupplierThreshold::class)->assess($this->entity->fresh());
    }

    private function runAlertJob(): void
    {
        (new SmallSupplierThresholdAlertJob($this->entity->id))->handle(app(SmallSupplierThreshold::class));
    }

    /**
     * An invoice already issued on $issuedOn, valued at $valueCad.
     */
    private function supply(
        string $issuedOn,
        ?string $valueCad,
        ?Business $business = null,
        InvoiceStatus $status = InvoiceStatus::Sent,
    ): Invoice {
        return Invoice::factory()
            ->for($business ?? $this->business)
            ->status($status)
            ->create([
                'issued_on' => $issuedOn,
                'supply_value_cad' => $valueCad,
            ]);
    }

    /**
     * @param  list<array{0: InvoiceLineType, 1: string}>  $lines
     */
    private function draftWithLines(string $currency, array $lines, string $period = '2026-08'): Invoice
    {
        $client = Client::factory()->for($this->business)->create(['billing_currency' => $currency]);
        $invoice = Invoice::factory()
            ->for($this->business)
            ->for($client)
            ->forPeriod($period)
            ->create(['issue_currency' => $currency]);

        $total = '0.00';

        foreach ($lines as $index => [$type, $amount]) {
            InvoiceLine::factory()->for($invoice)->ofType($type)->create([
                'amount' => $amount,
                'display_order' => $index,
            ]);
            $total = bcadd($total, $amount, 2);
        }

        $invoice->update(['subtotal' => $total, 'total' => $total]);

        return $invoice;
    }
}
