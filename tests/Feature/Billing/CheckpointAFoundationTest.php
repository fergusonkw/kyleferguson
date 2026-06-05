<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use App\Enums\Billing\SyncStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckpointAFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_factory_creates_record_with_expected_defaults(): void
    {
        $business = Business::factory()->create();

        $this->assertSame('CAD', $business->default_currency);
        $this->assertSame(['CAD', 'USD'], $business->supported_currencies);
        $this->assertSame('bank_of_canada', $business->fx_source);
        $this->assertNull($business->tax_registered_from);
        $this->assertFalse($business->isTaxRegisteredOn(now()));
    }

    public function test_business_tax_registration_check_respects_effective_date(): void
    {
        $business = Business::factory()->taxRegistered(now()->subMonth())->create();

        $this->assertTrue($business->isTaxRegisteredOn(now()));
        $this->assertFalse($business->isTaxRegisteredOn(now()->subYear()));
    }

    public function test_client_belongs_to_business_and_casts_enums(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->withMarkup(MarkupType::Percent, 20)->create();

        $this->assertTrue($client->business->is($business));
        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertSame(MarkupType::Percent, $client->default_markup_type);
        $this->assertSame('20.0000', $client->default_markup_value);
    }

    public function test_project_inherits_markup_from_client_when_not_overridden(): void
    {
        $client = Client::factory()->withMarkup(MarkupType::Percent, 15)->create();
        $project = Project::factory()->for($client)->create();

        $this->assertSame(MarkupType::Percent, $project->effectiveMarkupType());
        $this->assertSame('15.0000', $project->effectiveMarkupValue());
    }

    public function test_project_overrides_client_markup_when_set(): void
    {
        $client = Client::factory()->withMarkup(MarkupType::Percent, 15)->create();
        $project = Project::factory()
            ->for($client)
            ->withMarkupOverride(MarkupType::FixedFee, 100)
            ->create();

        $this->assertSame(MarkupType::FixedFee, $project->effectiveMarkupType());
        $this->assertSame('100.0000', $project->effectiveMarkupValue());
    }

    public function test_cost_provider_encrypts_credentials_and_casts_enums(): void
    {
        $provider = CostProvider::factory()->create([
            'credentials' => ['token' => 'secret-token-value'],
        ]);

        $this->assertSame(CostProviderSlug::DigitalOcean, $provider->slug);
        $this->assertSame(SyncStatus::Never, $provider->last_sync_status);
        $this->assertSame('secret-token-value', $provider->credentials['token']);

        $raw = DB::table('cost_providers')->where('id', $provider->id)->value('credentials');
        $this->assertNotSame('secret-token-value', $raw);
        $this->assertStringNotContainsString('secret-token-value', (string) $raw);
    }

    public function test_cost_provider_sync_status_transitions(): void
    {
        $provider = CostProvider::factory()->create();

        $provider->markSyncRunning();
        $this->assertSame(SyncStatus::Running, $provider->fresh()->last_sync_status);

        $provider->markSyncSucceeded();
        $fresh = $provider->fresh();
        $this->assertSame(SyncStatus::Success, $fresh->last_sync_status);
        $this->assertNotNull($fresh->last_synced_at);

        $provider->markSyncFailed('Network error');
        $fresh = $provider->fresh();
        $this->assertSame(SyncStatus::Failed, $fresh->last_sync_status);
        $this->assertSame('Network error', $fresh->last_sync_error);
    }

    public function test_provider_resource_relationships(): void
    {
        $project = Project::factory()->linkedToDigitalOcean()->create();
        $resource = ProviderResource::factory()->attributedTo($project)->create();

        $this->assertTrue($resource->isAttributed());
        $this->assertTrue($resource->project->is($project));
        $this->assertSame($project->do_project_uuid, $resource->provider_project_uuid);
    }

    public function test_resource_assignment_current_scope(): void
    {
        $resource = ProviderResource::factory()->create();

        ResourceAssignment::factory()
            ->for($resource, 'providerResource')
            ->closed(now()->subDay())
            ->create(['observed_from' => now()->subWeek()]);

        $current = ResourceAssignment::factory()
            ->for($resource, 'providerResource')
            ->create(['observed_from' => now()->subDay()]);

        $this->assertTrue($current->isCurrent());
        $this->assertTrue($resource->currentAssignment->is($current));
        $this->assertCount(2, $resource->assignments);
    }

    public function test_project_status_terminated_state(): void
    {
        $project = Project::factory()->terminated()->create();

        $this->assertSame(ProjectStatus::Terminated, $project->status);
        $this->assertNotNull($project->terminated_at);
    }

    public function test_do_project_uuid_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        Project::factory()->linkedToDigitalOcean($uuid)->create();
        Project::factory()->linkedToDigitalOcean($uuid)->create();
    }
}
