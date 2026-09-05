<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCostProviderRequest;
use App\Http\Requests\Billing\UpdateCostProviderRequest;
use App\Jobs\Billing\SyncProviderBillingJob;
use App\Jobs\Billing\SyncProviderResourcesJob;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Services\AuditLogger;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\ProviderAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class CostProviderController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ProviderAdapterRegistry $registry,
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
        $slug = CostProviderSlug::from($validated['slug']);

        $provider = CostProvider::create([
            'business_id' => $validated['business_id'],
            'client_id' => $validated['client_id'] ?? null,
            'slug' => $slug,
            'display_name' => $validated['display_name'],
            'credentials' => [$this->credentialKeyFor($slug) => $validated['token']],
            'config' => $request->configPayload(),
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);
        $this->audit->logCritical('token_created', $provider, null,
            ['provider_id' => $provider->id, 'business_id' => $provider->business_id],
            ['billing', 'cost-provider', 'token']);

        $valid = $this->validateCredentials($provider);

        return response()->json([
            'success' => true,
            'message' => $valid
                ? "Cost provider created. Credentials verified against {$slug->label()}."
                : 'Cost provider created, but the credentials failed validation. Check them and rotate if needed.',
            'token_valid' => $valid,
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
                'client_id' => $costProvider->client_id,
                'slug' => $costProvider->slug->value,
                'account_per_client' => $costProvider->slug->isAccountPerClient(),
                'display_name' => $costProvider->display_name,
                'enabled' => $costProvider->enabled,
                'region' => $costProvider->config('region'),
                'monthly_fee' => $costProvider->config('monthly_fee'),
                'fee_currency' => $costProvider->config('fee_currency'),
                'supports_resource_sync' => $this->registry->supportsResourceSync($costProvider->slug),
                'supports_billing_sync' => $this->registry->supportsBillingSync($costProvider->slug),
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
            'config' => $request->configPayload(),
        ]);

        if (array_key_exists('client_id', $validated)) {
            $costProvider->client_id = $validated['client_id'];
        }

        $tokenRotated = false;
        if (! empty($validated['token'])) {
            $this->authorize('rotateToken', $costProvider);
            $costProvider->credentials = [
                $this->credentialKeyFor($costProvider->slug) => $validated['token'],
            ];
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
            $valid = $this->validateCredentials($costProvider);
            $message .= $valid ? ' New credentials verified.' : ' New credentials failed validation — please check them.';
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

    /**
     * Refresh the provider's resource inventory.
     */
    public function sync(CostProvider $costProvider): JsonResponse
    {
        $this->authorize('sync', $costProvider);

        if (($guard = $this->guardSync($costProvider)) !== null) {
            return $guard;
        }

        if (! $this->registry->supportsResourceSync($costProvider->slug)) {
            return response()->json([
                'success' => false,
                'message' => "{$costProvider->slug->label()} has no resource inventory to sync.",
            ], 422);
        }

        SyncProviderResourcesJob::dispatch($costProvider->id);

        return response()->json([
            'success' => true,
            'message' => 'Resource sync queued. Check back in a moment.',
        ]);
    }

    /**
     * Ingest the provider's costs for a period (defaults to the current month).
     */
    public function syncBilling(Request $request, CostProvider $costProvider): JsonResponse
    {
        $this->authorize('sync', $costProvider);

        if (($guard = $this->guardSync($costProvider)) !== null) {
            return $guard;
        }

        if (! $this->registry->supportsBillingSync($costProvider->slug)) {
            return response()->json([
                'success' => false,
                'message' => "Billing ingestion for {$costProvider->slug->label()} is not available yet.",
            ], 422);
        }

        $period = (string) $request->input('period', '');
        if (! BillingPeriod::isValid($period)) {
            $period = BillingPeriod::current();
        }

        SyncProviderBillingJob::dispatch($costProvider->id, $period);

        return response()->json([
            'success' => true,
            'message' => 'Billing sync queued for '.BillingPeriod::label($period).'.',
        ]);
    }

    /**
     * Clients selectable for an account-per-client provider.
     */
    public function availableClients(): JsonResponse
    {
        $this->authorize('viewAny', CostProvider::class);

        $business = $this->currentBusiness->get();

        $clients = $business === null
            ? collect()
            : Client::query()
                ->where('business_id', $business->id)
                ->orderBy('name')
                ->get(['id', 'name']);

        return response()->json(['success' => true, 'clients' => $clients]);
    }

    private function guardSync(CostProvider $costProvider): ?JsonResponse
    {
        if ($costProvider->enabled) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Provider is disabled. Enable it before syncing.',
        ], 422);
    }

    /**
     * Providers name their secret differently; the encrypted blob keeps the
     * provider's own vocabulary so adapters read what they expect.
     */
    private function credentialKeyFor(CostProviderSlug $slug): string
    {
        return match ($slug) {
            CostProviderSlug::Smtp2go => 'api_key',
            CostProviderSlug::DigitalOcean => 'token',
        };
    }

    private function validateCredentials(CostProvider $provider): bool
    {
        if (! $this->registry->supportsCredentialValidation($provider->slug)) {
            return false;
        }

        try {
            return $this->registry->credentialValidatorFor($provider->slug)->validateCredentials($provider);
        } catch (Throwable) {
            return false;
        }
    }
}
