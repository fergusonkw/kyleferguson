<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreProjectRequest;
use App\Http\Requests\Billing\UpdateProjectRequest;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Services\AuditLogger;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ProjectController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Project::class);

        return view('admin-v2.billing.projects.index', [
            'markupOptions' => MarkupType::options(),
            'statusOptions' => collect(ProjectStatus::cases())
                ->mapWithKeys(fn (ProjectStatus $s): array => [$s->value => $s->label()])
                ->all(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $search = (string) $request->input('search.value', '');

        $query = Project::query()->with('client')
            ->whereHas('client', function ($q) use ($business): void {
                if ($business !== null) {
                    $q->where('business_id', $business->id);
                }
            });
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('do_project_uuid', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $total = (clone $query)->count();
        $projects = $query->orderBy('name')->skip($start)->take($length)->get();

        $rows = $projects->map(fn (Project $p): array => [
            'id' => $p->id,
            'name' => e($p->name),
            'client' => e($p->client?->name ?? '—'),
            'do_linked' => $p->do_project_uuid
                ? '<span class="badge bg-success">Linked</span>'
                : '<span class="badge bg-default">Unlinked</span>',
            'status' => sprintf(
                '<span class="badge bg-%s">%s</span>',
                e($p->status->badgeColor()),
                e($p->status->label()),
            ),
            'markup' => $p->markup_type
                ? e($p->markup_type->label())
                : '<em class="text-default-400">inherits</em>',
            'actions' => view('admin-v2.billing.projects.partials.actions', ['project' => $p])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $rows,
        ]);
    }

    /**
     * Return clients available under the current business (for the project form).
     */
    public function availableClients(): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $business = $this->currentBusiness->get();
        $clients = \App\Models\Billing\Client::query()
            ->when($business, fn ($q) => $q->where('business_id', $business->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $clients]);
    }

    /**
     * DigitalOcean projects available for linking — sourced from previously
     * synced provider_resources for the current business. Returns one entry
     * per distinct (provider, provider_project_uuid).
     */
    public function availableDoProjects(): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $business = $this->currentBusiness->get();

        $rows = ProviderResource::query()
            ->whereNotNull('provider_project_uuid')
            ->when($business, function ($q) use ($business): void {
                $q->whereHas('costProvider', fn ($c) => $c->where('business_id', $business->id));
            })
            ->get(['provider_project_uuid', 'metadata'])
            ->groupBy('provider_project_uuid')
            ->map(fn ($group, $uuid) => [
                'uuid' => (string) $uuid,
                'name' => $group->first()->metadata['do_project_name'] ?? $uuid,
                'is_default' => (bool) ($group->first()->metadata['do_project_is_default'] ?? false),
            ])
            ->values();

        // Also include any UUIDs already linked to local projects (in case sync hasn't run yet).
        $linkedUuids = Project::query()
            ->whereNotNull('do_project_uuid')
            ->whereHas('client', function ($q) use ($business): void {
                if ($business !== null) {
                    $q->where('business_id', $business->id);
                }
            })
            ->pluck('do_project_uuid')
            ->unique();

        $known = $rows->pluck('uuid')->all();
        foreach ($linkedUuids as $uuid) {
            if (! in_array($uuid, $known, true)) {
                $rows->push(['uuid' => $uuid, 'name' => $uuid, 'is_default' => false]);
            }
        }

        return response()->json(['success' => true, 'data' => $rows->values()]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());
        $this->audit->logCreated($project, ['billing', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Project created.',
            'data' => $project,
        ]);
    }

    public function edit(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'project' => [
                'id' => $project->id,
                'client_id' => $project->client_id,
                'name' => $project->name,
                'do_project_uuid' => $project->do_project_uuid,
                'status' => $project->status->value,
                'markup_type' => $project->markup_type?->value,
                'markup_value' => $project->markup_value,
                'notes' => $project->notes,
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $original = $project->getRawOriginal();
        $project->update($request->validated());

        $markupChanged = ($original['markup_type'] ?? null) !== ($project->markup_type?->value)
            || (float) ($original['markup_value'] ?? 0) !== (float) ($project->markup_value ?? 0);
        $linkChanged = ($original['do_project_uuid'] ?? null) !== $project->do_project_uuid;

        $tags = ['billing', 'project'];
        if ($markupChanged || $linkChanged) {
            $event = $markupChanged ? 'markup_changed' : 'do_link_changed';
            $extraTag = $markupChanged ? 'markup' : 'do-link';
            $this->audit->logCritical($event, $project, $original, $project->getAttributes(), array_merge($tags, [$extraTag]));
        } else {
            $this->audit->logUpdated($project, $original, $tags);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project updated.',
            'data' => $project->fresh(),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->audit->logDeleted($project, ['billing', 'project']);
        $project->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project deleted.',
        ]);
    }

    public function unattributedResources(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $business = $this->currentBusiness->get();

        if ($request->wantsJson() || $request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 25);

            $query = ProviderResource::query()
                ->with('costProvider')
                ->whereNull('project_id')
                ->when($business, function ($q) use ($business): void {
                    $q->whereHas('costProvider', fn ($c) => $c->where('business_id', $business->id));
                });

            $total = (clone $query)->count();
            $rows = $query->orderByDesc('last_seen_at')->skip($start)->take($length)->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $total,
                'recordsFiltered' => $total,
                'data' => $rows->map(fn (ProviderResource $r): array => [
                    'id' => $r->id,
                    'provider' => e($r->costProvider?->display_name ?? '—'),
                    'type' => e($r->resource_type),
                    'name' => e($r->name ?? '—'),
                    'do_project_name' => e($r->metadata['do_project_name'] ?? '—'),
                    'is_default' => ! empty($r->metadata['do_project_is_default'])
                        ? '<span class="badge bg-warning">Default Project</span>'
                        : '',
                    'last_seen_at' => $r->last_seen_at?->diffForHumans() ?? '—',
                ])->all(),
            ]);
        }

        return view('admin-v2.billing.projects.unattributed');
    }
}
