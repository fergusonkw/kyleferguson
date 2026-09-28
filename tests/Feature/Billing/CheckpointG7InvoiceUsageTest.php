<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Services\Billing\Dto\UsageSnapshot;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\InvoicePdfRenderer;
use App\Services\Billing\Smtp2go\Dto\Smtp2goCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Usage on invoices: a service billed a flat fee for an allowance shows what
 * was drawn against it, and the figures are frozen at approval.
 *
 * SMTP2GO's cycle keeps counting after the month it describes has closed, so
 * an invoice reading it live would restate itself after the client had it.
 */
final class CheckpointG7InvoiceUsageTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    private CostProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'USD',
            'default_markup_type' => MarkupType::Passthrough,
            'default_markup_value' => 0,
            'default_markup_fee' => 0,
        ]);
        $this->provider = CostProvider::factory()
            ->for($this->business)
            ->smtp2go()
            ->create(['invoice_label' => 'SMTP2Go']);

        FxRate::factory()->pair('USD', 'USD')->forPeriod(self::PERIOD)->withRate(1.0)->create();

        // Approval values the supply in CAD for the small-supplier threshold.
        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(1.35)->create();
    }

    public function test_a_cycle_reports_usage_against_its_plan_allowance(): void
    {
        $usage = Smtp2goCycle::fromApiPayload([
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'cycle_used' => 7608,
            'cycle_remaining' => 42392,
            'cycle_max' => 50000,
        ])->usage();

        $this->assertSame('7,608 of 50,000 emails', $usage->summary());
        $this->assertFalse($usage->exceedsIncluded());
    }

    public function test_a_plan_without_a_stated_allowance_reports_consumption_alone(): void
    {
        $usage = Smtp2goCycle::fromApiPayload(['cycle_used' => 1200, 'cycle_max' => 0])->usage();

        $this->assertSame('1,200 emails', $usage->summary());
        $this->assertFalse($usage->exceedsIncluded());
    }

    public function test_usage_beyond_the_allowance_is_recognised(): void
    {
        $usage = new UsageSnapshot('SMTP2Go', 60000, 50000, 'emails');

        $this->assertTrue($usage->exceedsIncluded());
    }

    public function test_a_fractional_measure_keeps_its_decimals(): void
    {
        $usage = new UsageSnapshot('Storage', 1.5, 10, 'GB');

        $this->assertSame('1.50 of 10 GB', $usage->summary());
    }

    public function test_a_reading_without_a_figure_is_not_usage(): void
    {
        $this->assertNull(UsageSnapshot::fromArray(['unit' => 'emails']));
        $this->assertNull(UsageSnapshot::fromArray(['used' => 'lots']));
    }

    public function test_a_cost_line_takes_its_usage_label_from_the_providers_invoice_label(): void
    {
        $cost = $this->cost(30.00, used: 7608, included: 50000);

        $this->assertSame('SMTP2Go', $cost->usage()?->label);

        $this->provider->update(['invoice_label' => 'Email delivery']);

        $this->assertSame('Email delivery', $cost->fresh()->usage()?->label);
    }

    public function test_a_cost_that_reports_no_usage_has_none(): void
    {
        $cost = CostLineItem::factory()
            ->for($this->provider)
            ->forPeriod(self::PERIOD)
            ->usd(30.00)
            ->attributedTo($this->project())
            ->create();

        $this->assertNull($cost->usage());
    }

    public function test_a_draft_carries_the_usage_behind_its_hosting_line(): void
    {
        $this->cost(30.00, used: 7608, included: 50000);

        $line = $this->hostingLine($this->build());

        $this->assertSame(['7,608 of 50,000 emails'], $line->usageNotes());
        $this->assertNotNull($line->metadata['usage_captured_at'] ?? null);
    }

    public function test_usage_names_its_service_when_the_line_does_not(): void
    {
        $project = $this->project();
        $this->cost(30.00, used: 7608, included: 50000, project: $project);

        $storage = CostProvider::factory()
            ->for($this->business)
            ->create(['invoice_label' => 'Object storage']);

        CostLineItem::factory()
            ->for($storage)
            ->forPeriod(self::PERIOD)
            ->ofCategory(CostCategory::Storage)
            ->usd(10.00)
            ->reportingUsage(120, 250, 'GB')
            ->attributedTo($project)
            ->create();

        $this->assertSame(
            ['Object storage: 120 of 250 GB', 'SMTP2Go: 7,608 of 50,000 emails'],
            $this->hostingLine($this->build())->usageNotes(),
        );
    }

    public function test_rebuilding_a_draft_takes_the_usage_again(): void
    {
        $cost = $this->cost(30.00, used: 7608, included: 50000);
        $this->build();

        $cost->update(['metadata' => ['usage' => ['used' => 9100, 'included' => 50000, 'unit' => 'emails']]]);

        $this->assertSame(['9,100 of 50,000 emails'], $this->hostingLine($this->build())->usageNotes());
    }

    public function test_approval_freezes_the_usage_as_it_stands_then(): void
    {
        $cost = $this->cost(30.00, used: 7608, included: 50000);
        $invoice = $this->build();

        // The cycle keeps counting between generating the draft and approving it.
        $cost->update(['metadata' => ['usage' => ['used' => 9100, 'included' => 50000, 'unit' => 'emails']]]);

        $approved = app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(['9,100 of 50,000 emails'], $this->hostingLine($approved)->usageNotes());
    }

    public function test_usage_recorded_after_approval_does_not_reach_the_issued_invoice(): void
    {
        $cost = $this->cost(30.00, used: 7608, included: 50000);
        $approved = app(InvoiceApprover::class)->approve($this->build());

        // A later sync refreshes the closed period's usage, as SMTP2GO's
        // straddling cycle makes it do.
        $cost->update(['metadata' => ['usage' => ['used' => 41000, 'included' => 50000, 'unit' => 'emails']]]);

        $this->assertSame(['7,608 of 50,000 emails'], $this->hostingLine($approved->fresh())->usageNotes());
    }

    public function test_a_service_that_stops_reporting_usage_leaves_no_stale_reading(): void
    {
        $cost = $this->cost(30.00, used: 7608, included: 50000);
        $this->build();

        $cost->update(['metadata' => null]);

        $line = $this->hostingLine($this->build());

        $this->assertSame([], $line->usageNotes());
        $this->assertArrayNotHasKey('usage_captured_at', $line->metadata ?? []);
    }

    public function test_the_rendered_invoice_shows_the_usage(): void
    {
        $this->cost(30.00, used: 7608, included: 50000);
        $approved = app(InvoiceApprover::class)->approve($this->build());

        $html = app(InvoicePdfRenderer::class)->html($approved);

        $this->assertStringContainsString('7,608 of 50,000 emails', $html);
    }

    public function test_every_invoice_template_shows_the_usage(): void
    {
        $this->business->update(['invoice_template_view' => 'admin-v2.billing.invoices.templates.tracker-pull']);
        $this->cost(30.00, used: 7608, included: 50000);

        $approved = app(InvoiceApprover::class)->approve($this->build());
        $html = app(InvoicePdfRenderer::class)->html($approved);

        $this->assertSame('admin-v2.billing.invoices.templates.tracker-pull', $approved->template_view_snapshot);
        $this->assertStringContainsString('7,608 of 50,000 emails', $html);
    }

    public function test_the_operator_sees_the_usage_before_approving(): void
    {
        $this->cost(30.00, used: 7608, included: 50000);
        $invoice = $this->build();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('7,608 of 50,000 emails');
    }

    public function test_usage_does_not_move_the_amount_charged(): void
    {
        $this->cost(30.00, used: 7608, included: 50000);

        $invoice = $this->build();
        $before = $invoice->total;

        $approved = app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame('30.00', $before);
        $this->assertSame('30.00', $approved->total);
    }

    private function build(): Invoice
    {
        return app(InvoiceBuilder::class)->build($this->client, self::PERIOD);
    }

    private function project(string $name = 'A&M Snow Removal Site'): Project
    {
        return Project::factory()->for($this->client)->create([
            'name' => $name,
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
            'markup_fee' => 0,
        ]);
    }

    private function cost(float $usd, float $used, ?float $included = null, ?Project $project = null): CostLineItem
    {
        return CostLineItem::factory()
            ->for($this->provider)
            ->forPeriod(self::PERIOD)
            ->ofCategory(CostCategory::Email)
            ->usd($usd)
            ->reportingUsage($used, $included)
            ->attributedTo($project ?? $this->project())
            ->create();
    }

    private function hostingLine(Invoice $invoice): InvoiceLine
    {
        $line = $invoice->lines()
            ->where('line_type', InvoiceLineType::Hosting)
            ->whereNull('parent_id')
            ->firstOrFail();

        return $line;
    }
}
