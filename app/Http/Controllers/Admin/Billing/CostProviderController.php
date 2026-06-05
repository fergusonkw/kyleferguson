<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCostProviderRequest;
use App\Http\Requests\Billing\UpdateCostProviderRequest;
use App\Jobs\Billing\SyncDigitalOceanProjectsJob;
use App\Models\Billing\CostProvider;
use App\Services\AuditLogger;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\DigitalOcean\Client as DoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CostProviderController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly DoClient $doClient,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', CostProvider::class);

        return view('admin-v2.billing.cost-providers.index');
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostProvider::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $query = CostProvider::query();
        if ($business !== null) {
            $query->where('business_id', $business->id);
        }

        $total = (clone $query)->count();
        $providers = $query->orderBy('display_name')->skip($start)->take($length)->get();

        $rows = $providers->map(fn (CostProvider $p): array => [
            'id' => $p->id,
            'display_name' => e($p->display_name),
            'slug' => e($p->slug->label()),
            'enabled' => $p->enabled
                ? '<span class="badge bg-success">Enabled</span>'
                : '<span class="badge bg-default">Disabled</span>',
            'last_sync_status' => sprintf(
                '<span class="badge bg-%s">%s</span>',
                e($p->last_sync_status->badgeColor()),
                e($p->last_sync_status->label()),
            ),
            'last_synced_at' => $p->last_synced_at?->diffForHumans() ?? '<em class="text-default-400">never</em>',
            'actions' => view('admin-v2.billing.cost-providers.partials.actions', ['provider' => $p])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $rows,
        ]);
    }

    public function store(StoreCostProviderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $provider = CostProvider::create([
            'business_id' => $validated['business_id'],
            'slug' => $validated['slug'],
            'display_name' => $validated['display_name'],
            'credentials' => ['token' => $validated['token']],
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);
        $this->audit->logCritical('token_created', $provider, null,
            ['provider_id' => $provider->id, 'business_id' => $provider->business_id],
            ['billing', 'cost-provider', 'token']);

        $tokenValid = $this->doClient->validateToken($provider);

        return response()->json([
            'success' => true,
            'message' => $tokenValid
                ? 'Cost provider created. Token verified against DigitalOcean.'
                : 'Cost provider created, but the token failed validation. Check it and rotate if needed.',
            'token_valid' => $tokenValid,
            'data' => $provider->makeHidden('credentials'),
        ]);
    }

    public function edit(CostProvider $costProvider): JsonResponse
    {
        $this->authorize('view', $costProvider);

        return response()->json([
            'success' => true,
            'provider' => [
                'id' => $costProvider->id,
                'business_id' => $costProvider->business_id,
                'slug' => $costProvider->slug->value,
                'display_name' => $costProvider->display_name,
                'enabled' => $costProvider->enabled,
                'last_sync_status' => $costProvider->last_sync_status->value,
                'last_synced_at' => $costProvider->last_synced_at?->toIso8601String(),
                'last_sync_error' => $costProvider->last_sync_error,
            ],
        ]);
    }

    public function update(UpdateCostProviderRequest $request, CostProvider $costProvider): JsonResponse
    {
        $validated = $request->validated();
        $original = $costProvider->getOriginal();

        $costProvider->fill([
            'display_name' => $validated['display_name'],
            'enabled' => (bool) ($validated['enabled'] ?? false),
        ]);

        $tokenRotated = false;
        if (! empty($validated['token'])) {
            $this->authorize('rotateToken', $costProvider);
            $costProvider->credentials = ['token' => $validated['token']];
            $tokenRotated = true;
        }

        $costProvider->save();

        if ($tokenRotated) {
            $this->audit->logCritical('token_rotated', $costProvider, null,
                ['provider_id' => $costProvider->id, 'business_id' => $costProvider->business_id],
                ['billing', 'cost-provider', 'token']);
        } else {
            $this->audit->logUpdated($costProvider, $original, ['billing', 'cost-provider']);
        }

        $message = 'Cost provider updated.';
        if ($tokenRotated) {
            $valid = $this->doClient->validateToken($costProvider);
            $message .= $valid ? ' New token verified.' : ' New token failed validation — please check it.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    public function destroy(CostProvider $costProvider): JsonResponse
    {
        $this->authorize('delete', $costProvider);

        $this->audit->logCritical('token_deleted', $costProvider, null,
            ['provider_id' => $costProvider->id, 'business_id' => $costProvider->business_id],
            ['billing', 'cost-provider', 'token']);
        $costProvider->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cost provider deleted.',
        ]);
    }

    public function sync(CostProvider $costProvider): JsonResponse
    {
        $this->authorize('sync', $costProvider);

        if (! $costProvider->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Provider is disabled. Enable it before syncing.',
            ], 422);
        }

        if ($costProvider->slug === CostProviderSlug::DigitalOcean) {
            SyncDigitalOceanProjectsJob::dispatch($costProvider->id);

            return response()->json([
                'success' => true,
                'message' => 'Sync queued. Check back in a moment.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "No sync adapter for provider {$costProvider->slug->value}.",
        ], 422);
    }
}
