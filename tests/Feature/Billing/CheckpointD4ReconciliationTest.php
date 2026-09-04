<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\CostProviderSlug;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reconciliation reporting: what was ingested, what can be billed on, and what
 * still needs an operator decision.
 */
final class CheckpointD4ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    private CostProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->for($this->business)->create();
        $this->provider = CostProvider::factory()->for($this->business)->smtp2go()->create();
    }

    public function test_summary_splits_attributed_unattributed_and_overhead(): void
    {
        $project = Project::factory()->for($this->client)->create();

        $this->line(20.0, project: $project);
        $this->line(5.0);
        $this->line(7.0, category: CostCategory::Support);

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(20.0, $summary->attributedCost);
        $this->assertSame(5.0, $summary->unattributedCost);
        $this->assertSame(7.0, $summary->overheadCost);
        $this->assertSame(32.0, $summary->totalIngestedCost());
        $this->assertSame(3, $summary->lineItemCount);
    }

    public function test_provider_tax_is_part_of_the_cost_basis(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $this->line(100.0, tax: 13.0, project: $project);

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(113.0, $summary->attributedCost);
    }

    public function test_cost_gap_is_unavailable_without_a_provider_that_reports_its_own_total(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $this->line(20.0, project: $project);

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertFalse($summary->hasCostGap());
        $this->assertNull($summary->costGap);
        $this->assertNull($summary->providerReportedTotal);
    }

    public function test_smtp2go_does_not_claim_to_report_an_authoritative_total(): void
    {
        $this->assertFalse(CostProviderSlug::Smtp2go->reportsAuthoritativeTotal());
        $this->assertTrue(CostProviderSlug::DigitalOcean->reportsAuthoritativeTotal());
    }

    public function test_cost_gap_is_computed_once_a_reporting_provider_is_connected(): void
    {
        $doProvider = CostProvider::factory()->for($this->business)->create();
        $project = Project::factory()->for($this->client)->create();

        CostLineItem::factory()->for($doProvider)->forPeriod(self::PERIOD)->usd(80.0)
            ->attributedTo($project)->create();
        CostLineItem::factory()->for($doProvider)->forPeriod(self::PERIOD)->usd(20.0)->create();

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertTrue($summary->hasCostGap());
        $this->assertSame(100.0, $summary->providerReportedTotal);
        $this->assertSame(20.0, $summary->costGap);
    }

    public function test_a_fully_attributed_reporting_provider_has_no_gap(): void
    {
        $doProvider = CostProvider::factory()->for($this->business)->create();
        $project = Project::factory()->for($this->client)->create();

        CostLineItem::factory()->for($doProvider)->forPeriod(self::PERIOD)->usd(100.0)
            ->attributedTo($project)->create();

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(0.0, $summary->costGap);
        $this->assertFalse($summary->needsAttention());
    }

    public function test_summary_is_scoped_to_the_business_and_period(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $this->line(20.0, project: $project);
        $this->line(99.0, project: $project, period: '2026-07');

        $otherBusiness = Business::factory()->create();
        $otherProvider = CostProvider::factory()->for($otherBusiness)->create();
        CostLineItem::factory()->for($otherProvider)->forPeriod(self::PERIOD)->usd(500.0)->create();

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(20.0, $summary->attributedCost);
        $this->assertSame(1, $summary->lineItemCount);
    }

    public function test_empty_period_summarizes_to_zero_rather_than_failing(): void
    {
        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(0.0, $summary->totalIngestedCost());
        $this->assertSame(0, $summary->lineItemCount);
        $this->assertFalse($summary->needsAttention());
    }

    public function test_unattributed_resources_are_listed_for_the_business_only(): void
    {
        $mine = ProviderResource::factory()->for($this->provider, 'costProvider')->create();

        $project = Project::factory()->for($this->client)->create();
        ProviderResource::factory()->for($this->provider, 'costProvider')->attributedTo($project)->create();

        $otherBusiness = Business::factory()->create();
        $otherProvider = CostProvider::factory()->for($otherBusiness)->create();
        ProviderResource::factory()->for($otherProvider, 'costProvider')->create();

        $resources = $this->reporter()->unattributedResources($this->business->id);

        $this->assertCount(1, $resources);
        $this->assertTrue($resources->first()->is($mine));
    }

    public function test_summary_counts_unattributed_resources(): void
    {
        ProviderResource::factory()->count(2)->for($this->provider, 'costProvider')->create();

        $summary = $this->reporter()->summarize($this->business->id, self::PERIOD);

        $this->assertSame(2, $summary->unattributedResourceCount);
        $this->assertTrue($summary->needsAttention());
    }

    public function test_unattributed_cost_alone_needs_attention(): void
    {
        $this->line(5.0);

        $this->assertTrue($this->reporter()->summarize($this->business->id, self::PERIOD)->needsAttention());
    }

    public function test_overhead_alone_does_not_need_attention(): void
    {
        $this->line(7.0, category: CostCategory::Support);

        $this->assertFalse($this->reporter()->summarize($this->business->id, self::PERIOD)->needsAttention());
    }

    public function test_cost_rolls_up_per_project_largest_first(): void
    {
        $small = Project::factory()->for($this->client)->create(['name' => 'Small']);
        $large = Project::factory()->for($this->client)->create(['name' => 'Large']);

        $this->line(10.0, project: $small);
        $this->line(30.0, project: $large);
        $this->line(20.0, project: $large);

        $rows = $this->reporter()->costByProject($this->business->id, self::PERIOD);

        $this->assertCount(2, $rows);
        $this->assertSame('Large', $rows[0]['project_name']);
        $this->assertSame(50.0, $rows[0]['cost']);
        $this->assertSame($this->client->name, $rows[0]['client_name']);
        $this->assertSame('Small', $rows[1]['project_name']);
        $this->assertSame(10.0, $rows[1]['cost']);
    }

    public function test_project_rollup_excludes_unattributed_lines(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $this->line(10.0, project: $project);
        $this->line(99.0);

        $rows = $this->reporter()->costByProject($this->business->id, self::PERIOD);

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $rows[0]['cost']);
    }

    public function test_cost_rolls_up_per_client_across_their_projects(): void
    {
        $otherClient = Client::factory()->for($this->business)->create(['name' => 'Beta Corp']);
        $projectA = Project::factory()->for($this->client)->create();
        $projectB = Project::factory()->for($this->client)->create();
        $projectC = Project::factory()->for($otherClient)->create();

        $this->line(10.0, project: $projectA);
        $this->line(15.0, project: $projectB);
        $this->line(5.0, project: $projectC);

        $rows = $this->reporter()->costByClient($this->business->id, self::PERIOD);

        $this->assertCount(2, $rows);
        $this->assertSame($this->client->name, $rows[0]['client_name']);
        $this->assertSame(25.0, $rows[0]['cost']);
        $this->assertSame('Beta Corp', $rows[1]['client_name']);
        $this->assertSame(5.0, $rows[1]['cost']);
    }

    public function test_trailing_cost_returns_a_contiguous_series_oldest_first(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $this->line(10.0, project: $project, period: '2026-07');
        $this->line(25.0, project: $project, period: '2026-08');

        $series = $this->reporter()->trailingCost($this->business->id, 3, Carbon::parse('2026-08-15'));

        $this->assertCount(3, $series);
        $this->assertSame('2026-06', $series[0]['period']);
        $this->assertSame(0.0, $series[0]['cost']);
        $this->assertSame('2026-07', $series[1]['period']);
        $this->assertSame(10.0, $series[1]['cost']);
        $this->assertSame('2026-08', $series[2]['period']);
        $this->assertSame(25.0, $series[2]['cost']);
    }

    public function test_trailing_cost_includes_overhead_and_unattributed(): void
    {
        $this->line(5.0);
        $this->line(7.0, category: CostCategory::Support);

        $series = $this->reporter()->trailingCost($this->business->id, 1, Carbon::parse('2026-08-15'));

        $this->assertSame(12.0, $series[0]['cost']);
    }

    public function test_attribution_can_be_re_run_from_the_reconciliation_page(): void
    {
        // Ingestion and attribution run on separate schedules, so a freshly
        // synced cost sits unattributed until the nightly pass. Without an
        // on-demand trigger the operator waits overnight to see their own
        // change take effect — and an unattributed cost reaches no invoice.
        $project = Project::factory()->for($this->client)->create();
        $resource = ProviderResource::factory()->for($this->provider, 'costProvider')
            ->attributedTo($project)->create();

        \App\Models\Billing\ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => $project->id,
            'observed_from' => now()->subMonth(),
            'observed_to' => null,
        ]);

        $line = CostLineItem::factory()->forResource($resource)->forPeriod(self::PERIOD)->usd(30.0)->create();
        $this->assertNull($line->project_id);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.reconciliation.attribute'), ['period' => self::PERIOD])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($project->id, $line->fresh()->project_id);
        $this->assertNotNull($line->fresh()->attributed_at);
    }

    public function test_re_running_attribution_on_an_empty_period_says_so(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.reconciliation.attribute'), ['period' => '2026-01'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['message' => 'No costs ingested for January 2026 yet.']);
    }

    public function test_re_running_attribution_requires_billing_permission(): void
    {
        $this->actingAs($this->createUserWithRole(\App\Enums\Role::User->slug()))
            ->postJson(route('admin.billing.reconciliation.attribute'))
            ->assertForbidden();
    }

    private function reporter(): ReconciliationReporter
    {
        return app(ReconciliationReporter::class);
    }

    private function line(
        float $amount,
        float $tax = 0.0,
        ?Project $project = null,
        CostCategory $category = CostCategory::Email,
        string $period = self::PERIOD,
    ): CostLineItem {
        $factory = CostLineItem::factory()
            ->for($this->provider)
            ->forPeriod($period)
            ->ofCategory($category)
            ->usd($amount, $tax);

        if ($project !== null) {
            $factory = $factory->attributedTo($project);
        }

        return $factory->create();
    }
}
