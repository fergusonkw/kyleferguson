<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\SyncStatus;
use App\Jobs\Billing\SyncProviderResourcesJob;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\DigitalOcean\Client as DoClient;
use App\Services\Billing\DigitalOcean\Dto\DoResource;
use App\Services\Billing\DigitalOcean\ProjectSync;
use App\Services\Billing\ProviderAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

final class CheckpointBSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_UUID = '00000000-0000-0000-0000-000000000001';

    private const ACME_UUID = '00000000-0000-0000-0000-000000000002';

    private const BETA_UUID = '00000000-0000-0000-0000-000000000003';

    /** @var array<string, list<array<string, mixed>>> */
    private array $fakeResourcesByProject = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_do_resource_parses_urn(): void
    {
        $parsed = DoResource::parseUrn('do:droplet:12345');

        $this->assertSame('droplet', $parsed['type']);
        $this->assertSame('12345', $parsed['id']);
    }

    public function test_do_resource_rejects_invalid_urn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DoResource::parseUrn('not:a:do:urn');
    }

    public function test_validate_token_returns_true_on_success(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account*' => Http::response(['account' => ['email' => 'a@b.c']], 200),
        ]);

        $provider = CostProvider::factory()->create();
        $valid = app(DoClient::class)->validateToken($provider);

        $this->assertTrue($valid);
    }

    public function test_validate_token_returns_false_on_unauthorized(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $provider = CostProvider::factory()->create();
        $valid = app(DoClient::class)->validateToken($provider);

        $this->assertFalse($valid);
    }

    public function test_sync_creates_resources_and_attributes_to_known_projects(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $acmeProject = Project::factory()
            ->for($client)
            ->linkedToDigitalOcean(self::ACME_UUID)
            ->create();
        $provider = CostProvider::factory()->for($business)->create();

        $this->fakeDigitalOceanApi([
            self::DEFAULT_UUID => [
                $this->resourcePayload('do:droplet:111', 'default-droplet'),
            ],
            self::ACME_UUID => [
                $this->resourcePayload('do:droplet:222', 'web'),
                $this->resourcePayload('do:dbaas:abc-1', 'mysql'),
            ],
        ]);

        $sync = app(ProjectSync::class);
        $observed = $sync->syncProjectsAndResources($provider);

        $this->assertSame(3, $observed);
        $this->assertSame(SyncStatus::Success, $provider->fresh()->last_sync_status);
        $this->assertNotNull($provider->fresh()->last_synced_at);

        $defaultRes = ProviderResource::query()
            ->where('provider_resource_id', '111')->firstOrFail();
        $this->assertNull($defaultRes->project_id);
        $this->assertSame(self::DEFAULT_UUID, $defaultRes->provider_project_uuid);

        $acmeWeb = ProviderResource::query()
            ->where('provider_resource_id', '222')->firstOrFail();
        $this->assertSame($acmeProject->id, $acmeWeb->project_id);

        $this->assertCount(3, ResourceAssignment::query()->whereNull('observed_to')->get());
    }

    public function test_sync_is_idempotent_when_remote_state_unchanged(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        Project::factory()->for($client)->linkedToDigitalOcean(self::ACME_UUID)->create();
        $provider = CostProvider::factory()->for($business)->create();

        $this->fakeDigitalOceanApi([
            self::ACME_UUID => [
                $this->resourcePayload('do:droplet:222', 'web'),
            ],
        ]);

        $sync = app(ProjectSync::class);
        $sync->syncProjectsAndResources($provider);
        $sync->syncProjectsAndResources($provider);

        $this->assertSame(1, ProviderResource::count());
        $this->assertSame(1, ResourceAssignment::count());
    }

    public function test_sync_records_history_when_resource_moves_between_projects(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $acme = Project::factory()->for($client)->linkedToDigitalOcean(self::ACME_UUID)->create();
        $beta = Project::factory()->for($client)->linkedToDigitalOcean(self::BETA_UUID)->create();
        $provider = CostProvider::factory()->for($business)->create();

        $this->fakeDigitalOceanApi([
            self::ACME_UUID => [$this->resourcePayload('do:droplet:222', 'web')],
            self::BETA_UUID => [],
        ]);
        app(ProjectSync::class)->syncProjectsAndResources($provider);

        $this->travel(1)->hour();

        $this->fakeDigitalOceanApi([
            self::ACME_UUID => [],
            self::BETA_UUID => [$this->resourcePayload('do:droplet:222', 'web')],
        ]);
        app(ProjectSync::class)->syncProjectsAndResources($provider);

        $resource = ProviderResource::query()
            ->where('provider_resource_id', '222')->firstOrFail();

        $this->assertSame($beta->id, $resource->project_id);
        $this->assertSame(self::BETA_UUID, $resource->provider_project_uuid);

        $assignments = $resource->assignments()->orderBy('observed_from')->get();
        $this->assertCount(2, $assignments);
        $this->assertSame($acme->id, $assignments[0]->project_id);
        $this->assertNotNull($assignments[0]->observed_to);
        $this->assertSame($beta->id, $assignments[1]->project_id);
        $this->assertNull($assignments[1]->observed_to);
    }

    public function test_sync_marks_provider_failed_on_exception(): void
    {
        Http::fake([
            'api.digitalocean.com/*' => Http::response(['message' => 'server error'], 500),
        ]);

        $provider = CostProvider::factory()->create();

        try {
            app(ProjectSync::class)->syncProjectsAndResources($provider);
            $this->fail('Expected exception to be thrown');
        } catch (Throwable) {
            // expected
        }

        $fresh = $provider->fresh();
        $this->assertSame(SyncStatus::Failed, $fresh->last_sync_status);
        $this->assertNotNull($fresh->last_sync_error);
    }

    public function test_job_skips_disabled_provider(): void
    {
        $provider = CostProvider::factory()->create(['enabled' => false]);

        (new SyncProviderResourcesJob($provider->id))->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(SyncStatus::Never, $provider->fresh()->last_sync_status);
    }

    public function test_job_can_be_dispatched(): void
    {
        Bus::fake();

        $provider = CostProvider::factory()->create();

        SyncProviderResourcesJob::dispatch($provider->id);

        Bus::assertDispatched(
            SyncProviderResourcesJob::class,
            fn (SyncProviderResourcesJob $job) => $job->costProviderId === $provider->id,
        );
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $resourcesByProject
     */
    private function fakeDigitalOceanApi(array $resourcesByProject): void
    {
        $this->fakeResourcesByProject = $resourcesByProject;

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/v2/projects?') || str_ends_with(strtok($url, '?'), '/v2/projects')) {
                $payloads = [];
                foreach (array_keys($this->fakeResourcesByProject) as $uuid) {
                    $payloads[] = [
                        'id' => $uuid,
                        'name' => 'project-'.substr($uuid, -1),
                        'description' => '',
                        'is_default' => $uuid === self::DEFAULT_UUID,
                    ];
                }

                return Http::response([
                    'projects' => $payloads,
                    'links' => ['pages' => []],
                ], 200);
            }

            foreach ($this->fakeResourcesByProject as $uuid => $resources) {
                if (str_contains($url, "/v2/projects/{$uuid}/resources")) {
                    return Http::response([
                        'resources' => $resources,
                        'links' => ['pages' => []],
                    ], 200);
                }
            }

            return Http::response(['message' => 'unexpected request: '.$url], 500);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload(string $urn, string $name): array
    {
        return [
            'urn' => $urn,
            'name' => $name,
            'status' => 'ok',
            'assigned_at' => now()->toIso8601String(),
        ];
    }
}
