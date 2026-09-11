<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\LegalEntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreLegalEntityRequest;
use App\Http\Requests\Billing\UpdateLegalEntityRequest;
use App\Models\Billing\LegalEntity;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The people and corporations behind the businesses. GST/HST registration and
 * the small-supplier threshold are configured here, not per trade name.
 */
final class LegalEntityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('viewAny', LegalEntity::class);

        return view('admin-v2.billing.legal-entities.index', [
            'entityTypes' => LegalEntityType::options(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LegalEntity::class);

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = (string) $request->input('search.value', '');

        $query = LegalEntity::query()->with(['businesses:id,legal_entity_id,name', 'associates:id,name']);

        if ($searchValue !== '') {
            $query->whereLike('name', "%{$searchValue}%");
        }

        $total = LegalEntity::count();
        $filtered = $query->count();

        $entities = $query->orderBy('name')->skip($start)->take($length)->get();

        $rows = $entities->map(fn (LegalEntity $entity): array => [
            'id' => $entity->id,
            'name' => e($entity->name),
            'entity_type' => e($entity->entity_type->label()),
            'businesses' => $entity->businesses->isEmpty()
                ? '<span class="text-default-400">—</span>'
                : e($entity->businesses->pluck('name')->sort()->implode(', ')),
            'associates' => $entity->associates->isEmpty()
                ? '<span class="text-default-400">—</span>'
                : e($entity->associates->pluck('name')->sort()->implode(', ')),
            'tax_registered' => $entity->tax_registered_from
                ? '<span class="badge bg-success">Registered '.e($entity->tax_registered_from->format('M j, Y')).'</span>'
                : '<span class="badge bg-default">Unregistered</span>',
            'actions' => view('admin-v2.billing.legal-entities.partials.actions', ['entity' => $entity])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    /**
     * Entities an entity could be associated with — every other one.
     */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', LegalEntity::class);

        return response()->json([
            'success' => true,
            'entities' => LegalEntity::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreLegalEntityRequest $request): JsonResponse
    {
        $entity = DB::transaction(function () use ($request): LegalEntity {
            $entity = LegalEntity::create($request->safe()->except('associate_ids'));
            $entity->syncAssociates($request->validated('associate_ids') ?? []);

            return $entity;
        });

        $this->audit->logCreated($entity, ['billing', 'legal-entity']);

        return response()->json([
            'success' => true,
            'message' => 'Legal entity created successfully.',
            'data' => $entity,
        ]);
    }

    public function edit(LegalEntity $legalEntity): JsonResponse
    {
        $this->authorize('view', $legalEntity);

        return response()->json([
            'success' => true,
            'entity' => [
                'id' => $legalEntity->id,
                'name' => $legalEntity->name,
                'entity_type' => $legalEntity->entity_type->value,
                'tax_registered_from' => $legalEntity->tax_registered_from?->format('Y-m-d'),
                'threshold_warning_percent' => $legalEntity->threshold_warning_percent,
                'associate_ids' => $legalEntity->associates()->pluck('legal_entities.id')->all(),
            ],
        ]);
    }

    public function update(UpdateLegalEntityRequest $request, LegalEntity $legalEntity): JsonResponse
    {
        $original = $legalEntity->getOriginal();

        DB::transaction(function () use ($request, $legalEntity): void {
            $legalEntity->update($request->safe()->except('associate_ids'));
            $legalEntity->syncAssociates($request->validated('associate_ids') ?? []);
        });

        $this->audit->logUpdated($legalEntity, $original, ['billing', 'legal-entity']);

        return response()->json([
            'success' => true,
            'message' => 'Legal entity updated successfully.',
            'data' => $legalEntity->fresh(),
        ]);
    }

    public function destroy(LegalEntity $legalEntity): JsonResponse
    {
        $this->authorize('delete', $legalEntity);

        if ($legalEntity->businesses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a legal entity that still has businesses under it. Move them first.',
            ], 422);
        }

        $this->audit->logDeleted($legalEntity, ['billing', 'legal-entity']);
        $legalEntity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Legal entity deleted successfully.',
        ]);
    }
}
