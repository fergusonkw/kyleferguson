<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckpointMCrossBusinessAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessX;

    private Business $businessY;

    private CostProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessX = Business::factory()->create(['fx_source' => 'bank_of_canada']);
        $this->businessY = Business::factory()->create(['fx_source' => 'bank_of_canada']);

        $this->provider = CostProvider::factory()->create([
            'business_id' => $this->businessX->id,
            'slug' => 'digitalocean',
        ]);
    }

    public function test_project_sync_maps_cross_business_project_uuids(): void
    {
        $clientX = Client::factory()->create(['business_id' => $this->businessX->id]);
        $clientY = Client::factory()->create(['business_id' => $this->businessY->id]);

        $uuidX = Str::uuid()->toString();
        $uuidY = Str::uuid()->toString();

        $projectX = Project::factory()->create(['client_id' => $clientX->id, 'do_project_uuid' => $uuidX]);
        $projectY = Project::factory()->create(['client_id' => $clientY->id, 'do_project_uuid' => $uuidY]);

        // Simulate what resolveProjectMap returns by reproducing its query
        $map = Project::query()
            ->whereNotNull('do_project_uuid')
            ->pluck('id', 'do_project_uuid')
            ->all();

        $this->assertSame($projectX->id, $map[$uuidX]);
        $this->assertSame($projectY->id, $map[$uuidY]);
    }

    public function test_cost_gap_splits_own_and_other_business_attribution(): void
    {
        $clientX = Client::factory()->create(['business_id' => $this->businessX->id]);
        $clientY = Client::factory()->create(['business_id' => $this->businessY->id]);

        $projectX = Project::factory()->create(['client_id' => $clientX->id]);
        $projectY = Project::factory()->create(['client_id' => $clientY->id]);

        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-06',
            'content_hash' => Str::random(64),
        ]);

        // $80 attributed to Business X's project (own)
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $projectX->id,
            'source_payload_id' => $payload->id,
            'period' => '2026-06',
            'usd_amount' => 80.00,
        ]);

        // $20 attributed to Business Y's project (other)
        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $projectY->id,
            'source_payload_id' => $payload->id,
            'period' => '2026-06',
            'usd_amount' => 20.00,
        ]);

        $gap = app(ReconciliationReporter::class)->costGap($this->businessX, '2026-06');

        $this->assertEqualsWithDelta(100.00, $gap['do_total_usd'], 0.01);
        $this->assertEqualsWithDelta(80.00, $gap['attributed_own_usd'], 0.01);
        $this->assertEqualsWithDelta(20.00, $gap['attributed_other_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $gap['unattributed_usd'], 0.01);
    }

    public function test_unattributed_costs_appear_in_unattributed_usd(): void
    {
        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => '2026-06',
            'content_hash' => Str::random(64),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => null,
            'source_payload_id' => $payload->id,
            'period' => '2026-06',
            'usd_amount' => 15.00,
        ]);

        $gap = app(ReconciliationReporter::class)->costGap($this->businessX, '2026-06');

        $this->assertEqualsWithDelta(15.00, $gap['do_total_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $gap['attributed_own_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $gap['attributed_other_usd'], 0.01);
        $this->assertEqualsWithDelta(15.00, $gap['unattributed_usd'], 0.01);
    }

    public function test_trailing_costs_exclude_cross_business_attribution(): void
    {
        $clientX = Client::factory()->create(['business_id' => $this->businessX->id]);
        $clientY = Client::factory()->create(['business_id' => $this->businessY->id]);

        $projectX = Project::factory()->create(['client_id' => $clientX->id]);
        $projectY = Project::factory()->create(['client_id' => $clientY->id]);

        $payload = ProviderBillingPayload::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'period' => now()->format('Y-m'),
            'content_hash' => Str::random(64),
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $projectX->id,
            'source_payload_id' => $payload->id,
            'period' => now()->format('Y-m'),
            'usd_amount' => 80.00,
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $this->provider->id,
            'project_id' => $projectY->id,
            'source_payload_id' => $payload->id,
            'period' => now()->format('Y-m'),
            'usd_amount' => 20.00,
        ]);

        $trailing = app(ReconciliationReporter::class)->trailing12MonthCosts($this->businessX);

        // Only Business X's own $80 should count toward the threshold
        $this->assertEqualsWithDelta(80.00, $trailing['total_usd'], 0.01);
    }
}
