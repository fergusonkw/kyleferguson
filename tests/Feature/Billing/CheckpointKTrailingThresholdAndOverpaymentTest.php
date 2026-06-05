<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckpointKTrailingThresholdAndOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'fx_source' => 'bank_of_canada',
            'trailing_12mo_threshold_usd' => null,
        ]);
        $this->client = Client::factory()->create(['business_id' => $this->business->id]);
    }

    // --- Trailing threshold ---

    public function test_no_threshold_warning_when_threshold_not_set(): void
    {
        $reporter = app(ReconciliationReporter::class);
        $status = $reporter->trailingThresholdStatus($this->business);

        $this->assertFalse($status['exceeded']);
        $this->assertNull($status['threshold_usd']);
        $this->assertNull($status['percentage']);
    }

    public function test_not_exceeded_when_costs_below_threshold(): void
    {
        $this->business->update(['trailing_12mo_threshold_usd' => 10000.00]);

        $provider = CostProvider::factory()->create(['business_id' => $this->business->id]);
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $provider->id,
            'period' => now()->format('Y-m'),
        ]);
        $project = Project::factory()->create(['client_id' => $this->client->id]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => now()->format('Y-m'),
            'usd_amount' => 500.00,
        ]);

        $reporter = app(ReconciliationReporter::class);
        $status = $reporter->trailingThresholdStatus($this->business);

        $this->assertFalse($status['exceeded']);
        $this->assertEqualsWithDelta(500.00, $status['total_usd'], 0.01);
    }

    public function test_exceeded_when_costs_above_threshold(): void
    {
        $this->business->update(['trailing_12mo_threshold_usd' => 400.00]);

        $provider = CostProvider::factory()->create(['business_id' => $this->business->id]);
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $provider->id,
            'period' => now()->format('Y-m'),
        ]);
        $project = Project::factory()->create(['client_id' => $this->client->id]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => now()->format('Y-m'),
            'usd_amount' => 500.00,
        ]);

        $reporter = app(ReconciliationReporter::class);
        $status = $reporter->trailingThresholdStatus($this->business);

        $this->assertTrue($status['exceeded']);
        $this->assertEqualsWithDelta(125.0, $status['percentage'], 0.1);
    }

    public function test_threshold_percentage_computed_correctly(): void
    {
        $this->business->update(['trailing_12mo_threshold_usd' => 1000.00]);

        $provider = CostProvider::factory()->create(['business_id' => $this->business->id]);
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $provider->id,
            'period' => now()->format('Y-m'),
        ]);
        $project = Project::factory()->create(['client_id' => $this->client->id]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => now()->format('Y-m'),
            'usd_amount' => 750.00,
        ]);

        $reporter = app(ReconciliationReporter::class);
        $status = $reporter->trailingThresholdStatus($this->business);

        $this->assertFalse($status['exceeded']);
        $this->assertEqualsWithDelta(75.0, $status['percentage'], 0.1);
    }

    // --- Overpayment credit ---

    public function test_overpayment_credit_applied_to_next_draft(): void
    {
        // Prior invoice paid with overpayment
        $priorInvoice = Invoice::factory()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-0001',
            'status' => InvoiceStatus::Paid,
            'issue_currency' => 'CAD',
            'subtotal' => 100.00,
            'total' => 100.00,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'fx_rate_period' => '2026-05',
        ]);

        Payment::factory()->create([
            'invoice_id' => $priorInvoice->id,
            'amount' => 120.00, // $20 overpayment
            'method' => PaymentMethod::Etransfer,
            'received_at' => now(),
        ]);

        FxRate::factory()->create([
            'currency_from' => 'USD',
            'currency_to' => 'CAD',
            'period' => '2026-06',
            'rate' => 1.38,
        ]);

        $newInvoice = app(InvoiceBuilder::class)->build($this->business, $this->client, '2026-06');

        $creditLine = $newInvoice->lines()->where('line_type', InvoiceLineType::Credit->value)->first();
        $this->assertNotNull($creditLine);
        $this->assertEqualsWithDelta(20.00, $creditLine->amount, 0.01);
        $this->assertStringContainsString('Overpayment Credit', $creditLine->label);
        $this->assertStringContainsString('INV-0001', $creditLine->label);
    }

    public function test_overpayment_credit_reduces_invoice_total(): void
    {
        $priorInvoice = Invoice::factory()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-0001',
            'status' => InvoiceStatus::Paid,
            'issue_currency' => 'CAD',
            'subtotal' => 100.00,
            'total' => 100.00,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'fx_rate_period' => '2026-05',
        ]);

        Payment::factory()->create([
            'invoice_id' => $priorInvoice->id,
            'amount' => 125.00,
            'method' => PaymentMethod::Etransfer,
            'received_at' => now(),
        ]);

        FxRate::factory()->create([
            'currency_from' => 'USD',
            'currency_to' => 'CAD',
            'period' => '2026-06',
            'rate' => 1.0,
        ]);

        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $provider = CostProvider::factory()->create(['business_id' => $this->business->id]);
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $provider->id,
            'period' => '2026-06',
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => '2026-06',
            'usd_amount' => 200.00,
        ]);

        $newInvoice = app(InvoiceBuilder::class)->build($this->business, $this->client, '2026-06');

        // Subtotal = 200, credit = 25 (overpayment), total = 200 - 25 = 175
        $this->assertEqualsWithDelta(200.00, $newInvoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(175.00, $newInvoice->total, 0.01);
    }

    public function test_no_credit_when_exactly_paid(): void
    {
        $priorInvoice = Invoice::factory()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-0001',
            'status' => InvoiceStatus::Paid,
            'issue_currency' => 'CAD',
            'subtotal' => 100.00,
            'total' => 100.00,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'fx_rate_period' => '2026-05',
        ]);

        Payment::factory()->create([
            'invoice_id' => $priorInvoice->id,
            'amount' => 100.00,
            'method' => PaymentMethod::Etransfer,
            'received_at' => now(),
        ]);

        FxRate::factory()->create([
            'currency_from' => 'USD',
            'currency_to' => 'CAD',
            'period' => '2026-06',
            'rate' => 1.38,
        ]);

        $newInvoice = app(InvoiceBuilder::class)->build($this->business, $this->client, '2026-06');

        $creditCount = $newInvoice->lines()->where('line_type', InvoiceLineType::Credit->value)->count();
        $this->assertSame(0, $creditCount);
    }

    public function test_overpayment_credit_not_applied_twice(): void
    {
        $priorInvoice = Invoice::factory()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-0001',
            'status' => InvoiceStatus::Paid,
            'issue_currency' => 'CAD',
            'subtotal' => 100.00,
            'total' => 100.00,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'fx_rate_period' => '2026-05',
        ]);

        Payment::factory()->create([
            'invoice_id' => $priorInvoice->id,
            'amount' => 120.00,
            'method' => PaymentMethod::Etransfer,
            'received_at' => now(),
        ]);

        foreach (['2026-06', '2026-07'] as $period) {
            FxRate::factory()->create([
                'currency_from' => 'USD',
                'currency_to' => 'CAD',
                'period' => $period,
                'rate' => 1.38,
            ]);
        }

        $builder = app(InvoiceBuilder::class);

        // First draft
        $draft1 = $builder->build($this->business, $this->client, '2026-06');
        $this->assertSame(1, $draft1->lines()->where('line_type', InvoiceLineType::Credit->value)->count());

        // Second draft for a different period — overpayment already claimed
        $draft2 = $builder->build($this->business, $this->client, '2026-07');
        $this->assertSame(0, $draft2->lines()->where('line_type', InvoiceLineType::Credit->value)->count());
    }
}
