<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Services\Billing\RestatementDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckpointJRestatementDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private Project $project;

    private CostProvider $provider;

    private Invoice $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['fx_source' => 'bank_of_canada']);
        $this->client = Client::factory()->create(['business_id' => $this->business->id]);
        $this->project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->provider = CostProvider::factory()->create([
            'business_id' => $this->business->id,
            'slug' => 'digitalocean',
        ]);

        $this->draft = Invoice::factory()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
            'issue_currency' => 'CAD',
            'fx_rate_snapshot' => 1.38,
            'subtotal' => 100.00,
            'total' => 100.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $this->draft->id,
            'line_type' => InvoiceLineType::Hosting,
            'amount' => 100.00,
            'display_order' => 0,
        ]);
    }

    public function test_returns_zero_when_only_one_payload(): void
    {
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $payload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);

        $result = app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $this->assertSame(0, $result);
    }

    public function test_returns_zero_when_amounts_unchanged(): void
    {
        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        foreach ([$oldPayload, $newPayload] as $payload) {
            CostLineItem::factory()->create([
                'cost_provider_id' => $this->provider->id,
                'project_id' => $this->project->id,
                'source_payload_id' => $payload->id,
                'period' => '2026-05',
                'usd_amount' => 100.00,
            ]);
        }

        $result = app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $this->assertSame(0, $result);
    }

    public function test_applies_positive_adjustment_when_costs_increased(): void
    {
        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $oldPayload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $newPayload->id,
            'period' => '2026-05',
            'usd_amount' => 120.00,
        ]);

        $result = app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $this->assertSame(1, $result);

        $adjustmentLine = $this->draft->lines()
            ->where('line_type', InvoiceLineType::Adjustment->value)
            ->first();

        $this->assertNotNull($adjustmentLine);
        $this->assertEqualsWithDelta(20.00 * 1.38, $adjustmentLine->amount, 0.01);
        $this->assertStringContainsString('DO Restatement', $adjustmentLine->label);
        $this->assertStringContainsString('2026-05', $adjustmentLine->label);
    }

    public function test_applies_negative_adjustment_when_costs_decreased(): void
    {
        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $oldPayload->id,
            'period' => '2026-05',
            'usd_amount' => 120.00,
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $newPayload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);

        app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $adjustmentLine = $this->draft->lines()
            ->where('line_type', InvoiceLineType::Adjustment->value)
            ->first();

        $this->assertNotNull($adjustmentLine);
        $this->assertEqualsWithDelta(-20.00 * 1.38, $adjustmentLine->amount, 0.01);
    }

    public function test_ignores_project_with_no_open_draft(): void
    {
        $this->draft->update(['status' => InvoiceStatus::Sent]);

        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $oldPayload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $newPayload->id,
            'period' => '2026-05',
            'usd_amount' => 120.00,
        ]);

        $result = app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $this->assertSame(0, $result);
    }

    public function test_idempotent_no_duplicate_adjustment(): void
    {
        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $oldPayload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $newPayload->id,
            'period' => '2026-05',
            'usd_amount' => 120.00,
        ]);

        $detector = app(RestatementDetector::class);
        $first = $detector->detectAndApply($this->provider, '2026-05');
        $second = $detector->detectAndApply($this->provider, '2026-05');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);

        $this->assertSame(
            1,
            $this->draft->lines()->where('line_type', InvoiceLineType::Adjustment->value)->count()
        );
    }

    public function test_recalculates_invoice_totals_after_adjustment(): void
    {
        $oldPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now()->subHour(),
        ]);
        $newPayload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-05',
            'content_hash' => Str::random(64),
            'created_at' => now(),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $oldPayload->id,
            'period' => '2026-05',
            'usd_amount' => 100.00,
        ]);
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $this->project->id,
            'source_payload_id' => $newPayload->id,
            'period' => '2026-05',
            'usd_amount' => 120.00,
        ]);

        app(RestatementDetector::class)->detectAndApply($this->provider, '2026-05');

        $this->draft->refresh();

        // Original subtotal 100 + adjustment (20 * 1.38 = 27.60) = 127.60
        $this->assertEqualsWithDelta(127.60, $this->draft->subtotal, 0.01);
        $this->assertEqualsWithDelta(127.60, $this->draft->total, 0.01);
    }
}
