<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean;

use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\DigitalOcean\Dto\DoProject;
use App\Services\Billing\DigitalOcean\Dto\DoResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProjectSync
{
    public function __construct(private readonly Client $client) {}

    public function validateCredentials(CostProvider $provider): bool
    {
        return $this->client->validateToken($provider);
    }

    /**
     * Pull every project + resource for the provider, upserting `provider_resources`
     * and writing `resource_assignments` rows whenever a resource's project changes
     * (or appears for the first time). Returns the number of resources observed.
     */
    public function syncProjectsAndResources(CostProvider $provider): int
    {
        $provider->markSyncRunning();

        try {
            $observedAt = now();
            $projectUuidToLocal = $this->resolveProjectMap($provider);
            $observed = 0;

            foreach ($this->client->listProjects($provider) as $doProject) {
                $observed += $this->syncProjectResources(
                    provider: $provider,
                    doProject: $doProject,
                    localProjectId: $projectUuidToLocal[$doProject->uuid] ?? null,
                    observedAt: $observedAt,
                );
            }

            $provider->markSyncSucceeded();

            return $observed;
        } catch (Throwable $e) {
            $provider->markSyncFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Map of DO project UUID → local projects.id for every project belonging to
     * a client of this provider's business.
     *
     * @return array<string, int>
     */
    private function resolveProjectMap(CostProvider $provider): array
    {
        return Project::query()
            ->whereNotNull('do_project_uuid')
            ->whereHas('client', fn ($q) => $q->where('business_id', $provider->business_id))
            ->pluck('id', 'do_project_uuid')
            ->all();
    }

    private function syncProjectResources(
        CostProvider $provider,
        DoProject $doProject,
        ?int $localProjectId,
        Carbon $observedAt,
    ): int {
        $count = 0;

        foreach ($this->client->listProjectResources($provider, $doProject->uuid) as $doResource) {
            $this->upsertResource(
                provider: $provider,
                doResource: $doResource,
                doProject: $doProject,
                localProjectId: $localProjectId,
                observedAt: $observedAt,
            );
            $count++;
        }

        return $count;
    }

    private function upsertResource(
        CostProvider $provider,
        DoResource $doResource,
        DoProject $doProject,
        ?int $localProjectId,
        Carbon $observedAt,
    ): void {
        DB::transaction(function () use ($provider, $doResource, $doProject, $localProjectId, $observedAt): void {
            /** @var ProviderResource $resource */
            $resource = ProviderResource::query()
                ->where('cost_provider_id', $provider->id)
                ->where('provider_resource_id', $doResource->id)
                ->where('resource_type', $doResource->type)
                ->lockForUpdate()
                ->first()
                ?? new ProviderResource([
                    'cost_provider_id' => $provider->id,
                    'provider_resource_id' => $doResource->id,
                    'resource_type' => $doResource->type,
                    'first_seen_at' => $observedAt,
                ]);

            $previousProjectUuid = $resource->exists ? $resource->provider_project_uuid : null;
            $previousLocalProjectId = $resource->exists ? $resource->project_id : null;

            $resource->fill([
                'name' => $doResource->name ?? $resource->name,
                'provider_project_uuid' => $doProject->uuid,
                'project_id' => $localProjectId,
                'metadata' => [
                    'urn' => $doResource->urn,
                    'status' => $doResource->status,
                    'do_project_name' => $doProject->name,
                    'do_project_is_default' => $doProject->isDefault,
                ],
                'last_seen_at' => $observedAt,
            ]);

            if (! $resource->exists) {
                $resource->first_seen_at = $observedAt;
            }

            $resource->save();

            $assignmentChanged = $previousProjectUuid !== $doProject->uuid
                || $previousLocalProjectId !== $localProjectId;

            if ($assignmentChanged) {
                ResourceAssignment::query()
                    ->where('provider_resource_id', $resource->id)
                    ->whereNull('observed_to')
                    ->update(['observed_to' => $observedAt]);

                ResourceAssignment::create([
                    'provider_resource_id' => $resource->id,
                    'project_id' => $localProjectId,
                    'provider_project_uuid' => $doProject->uuid,
                    'observed_from' => $observedAt,
                    'observed_to' => null,
                ]);
            }
        });
    }
}
