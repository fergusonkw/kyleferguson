<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckpointFReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-06';

    public function test_unattributed_resource_count_returns_zero_when_all_mapped(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();

        ProviderResource::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
        ]);

        $reporter = app(ReconciliationReporter::class);

        $this->assertSame(0, $reporter->unattributedResourceCount($business));
    }

    public function test_unattributed_resource_count_returns_correct_count(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->create();

        ProviderResource::factory()->count(3)->create([
            'cost_provider_id' => $provider->id,
            'project_id' => null,
        ]);

        $reporter = app(ReconciliationReporter::class);

        $this->assertSame(3, $reporter->unattributedResourceCount($business));
    }

    public function test_cost_gap_returns_correct_figures(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod(self::PERIOD)->create();

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 100.0,
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => null,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 20.0,
        ]);

        $reporter = app(ReconciliationReporter::class);
        $gap = $reporter->costGap($business, self::PERIOD);

        $this->assertEqualsWithDelta(120.0, $gap['do_total_usd'], 0.001);
        $this->assertEqualsWithDelta(100.0, $gap['attributed_usd'], 0.001);
        $this->assertEqualsWithDelta(20.0, $gap['gap_usd'], 0.001);
    }

    public function test_cost_gap_is_zero_when_no_data(): void
    {
        $business = Business::factory()->create();

        $reporter = app(ReconciliationReporter::class);
        $gap = $reporter->costGap($business, self::PERIOD);

        $this->assertEqualsWithDelta(0.0, $gap['do_total_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $gap['gap_usd'], 0.001);
    }

    public function test_trailing_12_month_costs_sums_attributed_items(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();

        foreach (['2026-06', '2026-05', '2026-04'] as $period) {
            $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod($period)->create();
            CostLineItem::factory()->create([
                'cost_provider_id' => $provider->id,
                'project_id' => $project->id,
                'source_payload_id' => $payload->id,
                'period' => $period,
                'usd_amount' => 50.0,
            ]);
        }

        $reporter = app(ReconciliationReporter::class);
        $trailing = $reporter->trailing12MonthCosts($business);

        $this->assertEqualsWithDelta(150.0, $trailing['total_usd'], 0.001);
        $this->assertCount(12, $trailing['periods']);
    }

    public function test_trailing_12_month_excludes_unattributed_items(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod(self::PERIOD)->create();

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => null,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 999.0,
        ]);

        $reporter = app(ReconciliationReporter::class);
        $trailing = $reporter->trailing12MonthCosts($business);

        $this->assertEqualsWithDelta(0.0, $trailing['total_usd'], 0.001);
    }

    public function test_reconciliation_reporter_scopes_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $providerA = CostProvider::factory()->for($businessA)->create();
        $providerB = CostProvider::factory()->for($businessB)->create();

        ProviderResource::factory()->count(2)->create([
            'cost_provider_id' => $providerA->id,
            'project_id' => null,
        ]);
        ProviderResource::factory()->count(5)->create([
            'cost_provider_id' => $providerB->id,
            'project_id' => null,
        ]);

        $reporter = app(ReconciliationReporter::class);

        $this->assertSame(2, $reporter->unattributedResourceCount($businessA));
        $this->assertSame(5, $reporter->unattributedResourceCount($businessB));
    }
}
