<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\CostAttributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Attribution reads effective-dated assignment history, so a cost bills to
 * wherever its resource lived during the period — not wherever it lives now.
 */
final class CheckpointD3AttributionTest extends TestCase
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
        $this->provider = CostProvider::factory()->for($this->business)->create();
    }

    public function test_line_attributes_to_the_project_its_resource_belonged_to(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-07-01');
        $line = $this->line($resource);

        $result = $this->attribute();

        $this->assertSame($project->id, $line->fresh()->project_id);
        $this->assertNotNull($line->fresh()->attributed_at);
        $this->assertSame(1, $result->attributed);
        $this->assertSame(1, $result->processed);
    }

    public function test_a_resource_with_no_assignment_stays_unattributed(): void
    {
        $line = $this->line($this->resource());

        $result = $this->attribute();

        $this->assertNull($line->fresh()->project_id);
        $this->assertNotNull($line->fresh()->attributed_at);
        $this->assertSame(1, $result->unattributed);
    }

    public function test_an_assignment_with_no_project_stays_unattributed(): void
    {
        $resource = $this->resource();
        ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => null,
            'observed_from' => '2026-07-01',
            'observed_to' => null,
        ]);
        $line = $this->line($resource);

        $this->attribute();

        $this->assertNull($line->fresh()->project_id);
    }

    public function test_a_line_with_no_resource_stays_unattributed(): void
    {
        $line = CostLineItem::factory()->for($this->provider)->forPeriod(self::PERIOD)->create();

        $result = $this->attribute();

        $this->assertNull($line->fresh()->project_id);
        $this->assertSame(1, $result->unattributed);
    }

    public function test_non_attributable_categories_are_reported_as_overhead(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-07-01');
        $line = $this->line($resource, CostCategory::Support);

        $result = $this->attribute();

        $this->assertNull($line->fresh()->project_id);
        $this->assertSame(1, $result->overhead);
        $this->assertSame(0, $result->attributed);
    }

    public function test_overhead_clears_a_previously_attributed_project(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-07-01');
        $line = $this->line($resource, CostCategory::Credit);
        $line->update(['project_id' => $project->id]);

        $this->attribute();

        $this->assertNull($line->fresh()->project_id);
    }

    public function test_a_resource_that_moved_bills_to_where_it_lived_at_period_end(): void
    {
        $acme = Project::factory()->for($this->client)->create();
        $beta = Project::factory()->for($this->client)->create();
        $resource = $this->resource();

        $this->assign($resource, $acme, '2026-07-01', '2026-09-10');
        $this->assign($resource, $beta, '2026-09-10');
        $resource->update(['project_id' => $beta->id]);

        $line = $this->line($resource);

        $this->attribute();

        $this->assertSame($acme->id, $line->fresh()->project_id);
    }

    public function test_a_move_inside_the_period_bills_to_the_project_held_at_period_end(): void
    {
        $acme = Project::factory()->for($this->client)->create();
        $beta = Project::factory()->for($this->client)->create();
        $resource = $this->resource();

        $this->assign($resource, $acme, '2026-08-01', '2026-08-20');
        $this->assign($resource, $beta, '2026-08-20');

        $line = $this->line($resource);

        $this->attribute();

        $this->assertSame($beta->id, $line->fresh()->project_id);
    }

    public function test_a_resource_first_tracked_after_the_period_uses_its_first_assignment(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-09-15');
        $line = $this->line($resource);

        $this->attribute();

        $this->assertSame($project->id, $line->fresh()->project_id);
    }

    public function test_a_resource_unassigned_during_the_period_is_not_back_filled(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();

        ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => null,
            'observed_from' => '2026-08-01',
            'observed_to' => '2026-09-15',
        ]);
        $this->assign($resource, $project, '2026-09-15');

        $line = $this->line($resource);

        $this->attribute();

        $this->assertNull($line->fresh()->project_id);
    }

    public function test_attribution_is_idempotent_and_does_not_rewrite_unchanged_rows(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-07-01');
        $line = $this->line($resource);

        $first = $this->attribute();
        $stampedAt = $line->fresh()->attributed_at;

        $this->travel(1)->hour();
        $second = $this->attribute();

        $this->assertSame(1, $first->changed);
        $this->assertSame(0, $second->changed);
        $this->assertSame(1, $second->attributed);
        $this->assertEquals($stampedAt, $line->fresh()->attributed_at);
    }

    public function test_reattribution_follows_a_corrected_assignment(): void
    {
        $wrong = Project::factory()->for($this->client)->create();
        $right = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $assignment = $this->assign($resource, $wrong, '2026-07-01');
        $line = $this->line($resource);

        $this->attribute();
        $this->assertSame($wrong->id, $line->fresh()->project_id);

        $assignment->update(['project_id' => $right->id]);
        $result = $this->attribute();

        $this->assertSame($right->id, $line->fresh()->project_id);
        $this->assertSame(1, $result->changed);
    }

    public function test_attribution_is_scoped_to_the_business_and_period(): void
    {
        $project = Project::factory()->for($this->client)->create();
        $resource = $this->resource();
        $this->assign($resource, $project, '2026-07-01');

        $inScope = $this->line($resource);
        $otherPeriod = $this->line($resource, CostCategory::Compute, '2026-07');

        $otherBusiness = Business::factory()->create();
        $otherProvider = CostProvider::factory()->for($otherBusiness)->create();
        $otherLine = CostLineItem::factory()->for($otherProvider)->forPeriod(self::PERIOD)->create();

        $result = $this->attribute();

        $this->assertSame(1, $result->processed);
        $this->assertSame($project->id, $inScope->fresh()->project_id);
        $this->assertNull($otherPeriod->fresh()->attributed_at);
        $this->assertNull($otherLine->fresh()->attributed_at);
    }

    public function test_a_malformed_period_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/YYYY-MM/');

        app(CostAttributor::class)->attribute($this->business->id, '2026-13');
    }

    private function attribute(string $period = self::PERIOD): \App\Services\Billing\Dto\AttributionResult
    {
        return app(CostAttributor::class)->attribute($this->business->id, $period);
    }

    private function resource(): ProviderResource
    {
        return ProviderResource::factory()->for($this->provider, 'costProvider')->create();
    }

    private function assign(
        ProviderResource $resource,
        Project $project,
        string $from,
        ?string $to = null,
    ): ResourceAssignment {
        return ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => $project->id,
            'observed_from' => $from,
            'observed_to' => $to,
        ]);
    }

    private function line(
        ProviderResource $resource,
        CostCategory $category = CostCategory::Compute,
        string $period = self::PERIOD,
    ): CostLineItem {
        return CostLineItem::factory()
            ->forResource($resource)
            ->ofCategory($category)
            ->forPeriod($period)
            ->create();
    }
}
