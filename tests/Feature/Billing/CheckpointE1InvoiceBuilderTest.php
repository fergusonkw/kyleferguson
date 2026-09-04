<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Enums\Billing\RecurringCadence;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Draft generation: cost basis to issue currency to markup, plus recurring
 * items and carried credits — and the idempotency the scheduler relies on.
 */
final class CheckpointE1InvoiceBuilderTest extends TestCase
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

        $this->business = Business::factory()->create([
            'invoice_number_prefix' => 'KF-',
            'invoice_number_sequence' => 1,
            'late_fee_terms' => '2% monthly interest after 30 days.',
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Passthrough,
            'default_markup_value' => 0,
            'default_markup_fee' => 0,
        ]);
        $this->provider = CostProvider::factory()->for($this->business)->create();

        // USD 1.00 of cost bills as CAD 2.00, so conversion is unmistakable.
        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(2.0)->create();
    }

    public function test_draft_converts_the_usd_cost_basis_to_the_issue_currency(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);

        $invoice = $this->build();

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('CAD', $invoice->issue_currency);
        $this->assertSame('100.00', $invoice->total);
        $this->assertSame('2.00000000', $invoice->fx_rate_snapshot);
        $this->assertSame('bank_of_canada', $invoice->fx_rate_source);
        $this->assertSame(self::PERIOD, $invoice->fx_rate_period);
    }

    public function test_provider_tax_is_part_of_the_billed_cost_basis(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00, tax: 10.00);

        $this->assertSame('120.00', $this->build()->total);
    }

    public function test_percent_markup_is_applied_after_conversion(): void
    {
        $project = $this->project(MarkupType::Percent, percent: 25);
        $this->cost($project, 50.00);

        // 50 USD -> 100 CAD -> +25% = 125.00
        $this->assertSame('125.00', $this->build()->total);
    }

    public function test_fixed_fee_markup_adds_a_flat_amount(): void
    {
        $project = $this->project(MarkupType::FixedFee, fee: 40);
        $this->cost($project, 50.00);

        $this->assertSame('140.00', $this->build()->total);
    }

    public function test_hybrid_markup_applies_percent_then_fee(): void
    {
        $project = $this->project(MarkupType::Hybrid, percent: 10, fee: 15);
        $this->cost($project, 50.00);

        // 100 CAD +10% = 110, + 15 fee = 125.00
        $this->assertSame('125.00', $this->build()->total);
    }

    public function test_passthrough_bills_cost_only(): void
    {
        $project = $this->project(MarkupType::Passthrough);
        $this->cost($project, 50.00);

        $this->assertSame('100.00', $this->build()->total);
    }

    public function test_project_markup_overrides_the_client_default(): void
    {
        $this->client->update([
            'default_markup_type' => MarkupType::Percent,
            'default_markup_value' => 50,
        ]);
        $project = $this->project(MarkupType::Percent, percent: 10);
        $this->cost($project, 50.00);

        $this->assertSame('110.00', $this->build()->total);
    }

    public function test_project_without_markup_inherits_the_client_default(): void
    {
        $this->client->update([
            'default_markup_type' => MarkupType::Percent,
            'default_markup_value' => 30,
        ]);
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => null,
            'markup_value' => null,
            'markup_fee' => null,
        ]);
        $this->cost($project, 50.00);

        $this->assertSame('130.00', $this->build()->total);
    }

    public function test_each_project_gets_its_own_parent_line_with_its_own_markup(): void
    {
        $a = $this->project(MarkupType::Percent, percent: 10, name: 'Alpha');
        $b = $this->project(MarkupType::Percent, percent: 50, name: 'Beta');
        $this->cost($a, 50.00);
        $this->cost($b, 50.00);

        $invoice = $this->build();
        $parents = $invoice->topLevelLines()->where('line_type', InvoiceLineType::Hosting)->get();

        $this->assertCount(2, $parents);
        $this->assertSame('Hosting — Alpha', $parents->firstWhere('project_id', $a->id)->label);
        $this->assertSame('110.00', $parents->firstWhere('project_id', $a->id)->amount);
        $this->assertSame('150.00', $parents->firstWhere('project_id', $b->id)->amount);
        $this->assertSame('260.00', $invoice->total);
    }

    public function test_costs_are_broken_into_display_only_category_sub_items(): void
    {
        $project = $this->project();
        $this->cost($project, 30.00, category: CostCategory::Compute);
        $this->cost($project, 20.00, category: CostCategory::Database);

        $invoice = $this->build();
        $parent = $invoice->topLevelLines()->firstOrFail();
        $children = $parent->children;

        $this->assertCount(2, $children);
        $this->assertTrue($children->every(fn (InvoiceLine $l): bool => $l->is_display_only));
        $this->assertEqualsCanonicalizing(['Compute', 'Database'], $children->pluck('label')->all());
        $this->assertSame('60.00', $children->firstWhere('label', 'Compute')->amount);

        // Sub-items must not inflate the total.
        $this->assertSame('100.00', $invoice->total);
    }

    public function test_unattributed_costs_are_not_billed(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        CostLineItem::factory()->for($this->provider)->forPeriod(self::PERIOD)->usd(999.00)->create();

        $this->assertSame('100.00', $this->build()->total);
    }

    public function test_another_clients_costs_are_not_billed(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);

        $otherClient = Client::factory()->for($this->business)->create();
        $otherProject = Project::factory()->for($otherClient)->create();
        $this->cost($otherProject, 999.00);

        $this->assertSame('100.00', $this->build()->total);
    }

    public function test_costs_from_another_period_are_not_billed(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        $this->cost($project, 999.00, period: '2026-07');

        $this->assertSame('100.00', $this->build()->total);
    }

    public function test_recurring_templates_are_added_for_the_period(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        RecurringLineTemplate::factory()->for($this->client)
            ->amount(19.00, 'CAD')->window('2026-01-01')->create(['label' => 'Laravel Forge']);

        $invoice = $this->build();

        $recurring = $invoice->lines->firstWhere('line_type', InvoiceLineType::Recurring);
        $this->assertNotNull($recurring);
        $this->assertSame('Laravel Forge', $recurring->label);
        $this->assertSame('119.00', $invoice->total);
    }

    public function test_a_quarterly_template_is_skipped_outside_its_cadence(): void
    {
        RecurringLineTemplate::factory()->for($this->client)
            ->cadence(RecurringCadence::Quarterly)
            ->amount(90.00, 'CAD')->window('2026-01-01')->create();

        // 2026-08 is 7 months after January — not a quarterly anniversary.
        $this->assertSame('0.00', $this->build()->total);
    }

    public function test_a_template_in_the_wrong_currency_stops_generation(): void
    {
        RecurringLineTemplate::factory()->for($this->client)
            ->amount(19.00, 'USD')->window('2026-01-01')->create(['label' => 'Mispriced item']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is priced in USD');

        $this->build();
    }

    public function test_an_overpayment_carries_forward_as_a_credit(): void
    {
        $priorInvoice = Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod('2026-07')->withTotal(100.00)->sent()
            ->create(['invoice_number' => 'KF-00900']);
        Payment::factory()->for($priorInvoice)->amount(130.00)->create();

        $project = $this->project();
        $this->cost($project, 50.00);

        $invoice = $this->build();
        $credit = $invoice->lines->firstWhere('line_type', InvoiceLineType::Credit);

        $this->assertNotNull($credit);
        $this->assertSame('-30.00', $credit->amount);
        $this->assertStringContainsString('KF-00900', $credit->label);
        $this->assertSame('70.00', $invoice->total);
    }

    public function test_a_settled_prior_invoice_produces_no_credit(): void
    {
        $prior = Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod('2026-07')->withTotal(100.00)->sent()->create();
        Payment::factory()->for($prior)->amount(100.00)->create();

        $project = $this->project();
        $this->cost($project, 50.00);

        $this->assertNull($this->build()->lines->firstWhere('line_type', InvoiceLineType::Credit));
    }

    public function test_the_invoice_snapshots_everything_needed_to_reproduce_it(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);

        $invoice = $this->build();

        $this->assertSame('2% monthly interest after 30 days.', $invoice->late_fee_terms_snapshot);
        $this->assertSame($this->business->invoice_template_view, $invoice->template_view_snapshot);
        $this->assertSame($this->business->email_template_view, $invoice->email_template_view_snapshot);
        $this->assertSame($this->business->name, $invoice->business_snapshot['name']);
        $this->assertSame($this->client->name, $invoice->client_snapshot['name']);
        $this->assertSame('CAD', $invoice->client_snapshot['billing_currency']);
    }

    public function test_rebuilding_is_idempotent(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);

        $first = $this->build();
        $second = $this->build();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->invoice_number, $second->invoice_number);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('100.00', $second->total);
        $this->assertSame(1, $second->topLevelLines()->where('line_type', InvoiceLineType::Hosting)->count());
    }

    public function test_rebuilding_picks_up_newly_ingested_costs(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        $this->build();

        $this->cost($project, 25.00, reference: 'second');
        $rebuilt = $this->build();

        $this->assertSame('150.00', $rebuilt->total);
    }

    public function test_rebuilding_preserves_operator_added_lines(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        $invoice = $this->build();

        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Discount)
            ->amount(-10.00)->create(['label' => 'Goodwill discount']);
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)
            ->amount(25.00)->create(['label' => 'Consulting']);

        $rebuilt = $this->build();

        $this->assertNotNull($rebuilt->lines->firstWhere('label', 'Goodwill discount'));
        $this->assertNotNull($rebuilt->lines->firstWhere('label', 'Consulting'));
        $this->assertSame('115.00', $rebuilt->total);
    }

    public function test_an_issued_invoice_is_never_rebuilt(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);
        $invoice = $this->build();
        $invoice->update(['status' => InvoiceStatus::Sent]);

        $this->cost($project, 500.00, reference: 'late arrival');
        $rebuilt = $this->build();

        $this->assertSame($invoice->id, $rebuilt->id);
        $this->assertSame('100.00', $rebuilt->total);
    }

    public function test_invoice_numbers_advance_across_clients(): void
    {
        $a = $this->project();
        $this->cost($a, 10.00);

        $otherClient = Client::factory()->for($this->business)->create(['billing_currency' => 'CAD']);
        $otherProject = Project::factory()->for($otherClient)->create();
        $this->cost($otherProject, 10.00);

        $this->assertSame('KF-00001', $this->build()->invoice_number);
        $this->assertSame('KF-00002', app(InvoiceBuilder::class)->build($otherClient, self::PERIOD)->invoice_number);
    }

    public function test_a_usd_billed_client_needs_no_conversion(): void
    {
        $usdClient = Client::factory()->for($this->business)->create([
            'billing_currency' => 'USD',
            'default_markup_type' => MarkupType::Passthrough,
        ]);
        $usdProject = Project::factory()->for($usdClient)->create();
        $this->cost($usdProject, 50.00);

        $invoice = app(InvoiceBuilder::class)->build($usdClient, self::PERIOD);

        $this->assertSame('USD', $invoice->issue_currency);
        $this->assertSame('50.00', $invoice->total);
        $this->assertSame('internal', $invoice->fx_rate_source);
    }

    public function test_a_period_with_no_activity_produces_an_empty_draft(): void
    {
        $invoice = $this->build();

        $this->assertSame('0.00', $invoice->total);
        $this->assertCount(0, $invoice->lines);
    }

    public function test_no_tax_line_while_the_business_is_unregistered(): void
    {
        $project = $this->project();
        $this->cost($project, 50.00);

        $invoice = $this->build();

        $this->assertSame('0.00', $invoice->tax_total);
        $this->assertSame($invoice->subtotal, $invoice->total);
    }

    private function build(): Invoice
    {
        return app(InvoiceBuilder::class)->build($this->client, self::PERIOD);
    }

    private function project(
        ?MarkupType $type = null,
        float $percent = 0,
        float $fee = 0,
        string $name = 'Acme',
    ): Project {
        return Project::factory()->for($this->client)->create([
            'name' => $name,
            'markup_type' => $type,
            'markup_value' => $type === null ? null : $percent,
            'markup_fee' => $type === null ? null : $fee,
        ]);
    }

    private function cost(
        Project $project,
        float $usd,
        float $tax = 0.0,
        CostCategory $category = CostCategory::Compute,
        string $period = self::PERIOD,
        ?string $reference = null,
    ): CostLineItem {
        return CostLineItem::factory()
            ->for($this->provider)
            ->forPeriod($period)
            ->ofCategory($category)
            ->usd($usd, $tax)
            ->attributedTo($project)
            ->create(['source_reference' => $reference]);
    }
}
