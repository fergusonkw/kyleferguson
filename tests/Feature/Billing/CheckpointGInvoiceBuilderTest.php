<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\Cadence;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class CheckpointGInvoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-06';

    private Business $business;

    private Client $client;

    private CostProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD', 'USD'],
            'fx_source' => 'bank_of_canada',
        ]);

        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'CAD',
        ]);

        $this->provider = CostProvider::factory()->for($this->business)->create();

        FxRate::factory()->create([
            'currency_from' => 'USD',
            'currency_to' => 'CAD',
            'period' => self::PERIOD,
            'rate' => 1.38,
        ]);
    }

    public function test_invoice_builder_passthrough_markup(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => null,
            'markup_value' => null,
        ]);

        $this->client->update([
            'default_markup_type' => MarkupType::Passthrough,
            'default_markup_value' => 0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertEqualsWithDelta(138.0, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(138.0, $invoice->total, 0.01);
        $this->assertSame(1, $invoice->lines()->where('line_type', InvoiceLineType::Hosting->value)->count());
    }

    public function test_invoice_builder_percent_markup(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Percent,
            'markup_value' => 20.0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        // 100 USD * 1.20 markup * 1.38 FX = 165.60 CAD
        $this->assertEqualsWithDelta(165.60, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(165.60, $invoice->total, 0.01);
    }

    public function test_invoice_builder_fixed_fee_markup(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::FixedFee,
            'markup_value' => 50.0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        // (100 + 50) USD * 1.38 FX = 207.00 CAD
        $this->assertEqualsWithDelta(207.0, $invoice->subtotal, 0.01);
    }

    public function test_invoice_builder_hybrid_markup(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Hybrid,
            'markup_value' => 10.0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        // 100 USD * 1.10 * 1.38 FX = 151.80 CAD
        $this->assertEqualsWithDelta(151.80, $invoice->subtotal, 0.01);
    }

    public function test_invoice_builder_includes_recurring_line_templates(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 50.0);

        RecurringLineTemplate::factory()->create([
            'client_id' => $this->client->id,
            'project_id' => null,
            'label' => 'Monthly Support Retainer',
            'amount' => 200.0,
            'currency' => 'CAD',
            'cadence' => Cadence::Monthly,
            'active_from' => '2026-01-01',
            'active_to' => null,
        ]);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $recurringLines = $invoice->lines()->where('line_type', InvoiceLineType::Recurring->value)->get();
        $this->assertSame(1, $recurringLines->count());
        $this->assertEqualsWithDelta(200.0, $recurringLines->first()->amount, 0.01);
    }

    public function test_invoice_builder_skips_quarterly_template_in_non_quarter_month(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 50.0);

        RecurringLineTemplate::factory()->quarterly()->create([
            'client_id' => $this->client->id,
            'amount' => 500.0,
            'currency' => 'CAD',
            'active_from' => '2026-01-01',
        ]);

        // June (month 6) is not a quarter start (1, 4, 7, 10)
        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $this->assertSame(0, $invoice->lines()->where('line_type', InvoiceLineType::Recurring->value)->count());
    }

    public function test_invoice_builder_adds_tax_line_when_registered(): void
    {
        $this->business->update(['tax_registered_from' => '2026-01-01']);

        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $taxLines = $invoice->lines()->where('line_type', InvoiceLineType::Tax->value)->get();
        $this->assertSame(1, $taxLines->count());

        // 138.00 CAD * 5% = 6.90
        $this->assertEqualsWithDelta(6.90, $taxLines->first()->amount, 0.01);
        $this->assertEqualsWithDelta(144.90, $invoice->total, 0.01);
    }

    public function test_invoice_builder_no_tax_when_unregistered(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 100.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $this->assertSame(0, $invoice->lines()->where('line_type', InvoiceLineType::Tax->value)->count());
    }

    public function test_invoice_builder_is_idempotent(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 100.0);

        $builder = app(InvoiceBuilder::class);
        $first = $builder->build($this->business, $this->client, self::PERIOD);
        $second = $builder->build($this->business, $this->client, self::PERIOD);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_invoice_builder_multi_currency_usd_client(): void
    {
        $usdClient = Client::factory()->for($this->business)->create([
            'billing_currency' => 'USD',
        ]);

        FxRate::factory()->create([
            'currency_from' => 'USD',
            'currency_to' => 'USD',
            'period' => self::PERIOD,
            'rate' => 1.0,
        ]);

        $project = Project::factory()->for($usdClient)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $payload = ProviderBillingPayload::factory()->for($this->provider)->forPeriod(self::PERIOD)->create();

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 75.0,
        ]);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $usdClient, self::PERIOD);

        $this->assertSame('USD', $invoice->issue_currency);
        $this->assertEqualsWithDelta(75.0, $invoice->subtotal, 0.01);
    }

    public function test_invoice_approver_draft_to_approved(): void
    {
        $invoice = Invoice::factory()->draft()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
        ]);

        app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->approved_at);
    }

    public function test_invoice_approver_illegal_transition_throws(): void
    {
        $invoice = Invoice::factory()->draft()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
        ]);

        $this->expectException(RuntimeException::class);
        app(InvoiceApprover::class)->send($invoice);
    }

    public function test_invoice_approver_void_from_sent(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->sent()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
        ]);

        app(InvoiceApprover::class)->void($invoice);

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->voided_at);
    }

    public function test_invoice_number_allocator_formats_sequence(): void
    {
        $this->business->update(['invoice_number_prefix' => 'ACME-', 'invoice_number_sequence' => 1]);

        $project = Project::factory()->for($this->client)->create([
            'markup_type' => MarkupType::Passthrough,
            'markup_value' => 0,
        ]);

        $this->createCostItem($project, 10.0);

        $invoice = app(InvoiceBuilder::class)->build($this->business, $this->client, self::PERIOD);

        $this->assertSame('ACME-0001', $invoice->invoice_number);
        $this->assertSame(2, $this->business->fresh()->invoice_number_sequence);
    }

    private function createCostItem(Project $project, float $amount): CostLineItem
    {
        $payload = ProviderBillingPayload::factory()->for($this->provider)->forPeriod(self::PERIOD)->create();

        return CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => $amount,
            'usd_tax' => 0,
        ]);
    }
}
