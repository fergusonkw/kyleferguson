<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\CostLineItem;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CostAttributor;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReconciliationController extends Controller
{
    /** How many months back the period selector offers. */
    private const PERIOD_CHOICES = 12;

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ReconciliationReporter $reporter,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny-billing');

        $business = $this->currentBusiness->get();
        $period = $this->resolvePeriod($request);

        if ($business === null) {
            return view('admin-v2.billing.reconciliation.index', [
                'business' => null,
                'period' => $period,
                'periods' => $this->periodChoices(),
                'summary' => null,
                'byProject' => collect(),
                'byClient' => collect(),
                'unattributedResources' => collect(),
            ]);
        }

        return view('admin-v2.billing.reconciliation.index', [
            'business' => $business,
            'period' => $period,
            'periods' => $this->periodChoices(),
            'summary' => $this->reporter->summarize($business->id, $period),
            'byProject' => $this->reporter->costByProject($business->id, $period),
            'byClient' => $this->reporter->costByClient($business->id, $period),
            'unattributedResources' => $this->reporter->unattributedResources($business->id),
        ]);
    }

    /**
     * Cost line items for the DataTable.
     */
    public function lineItems(Request $request): JsonResponse
    {
        $this->authorize('viewAny-billing');

        $business = $this->currentBusiness->get();
        $period = $this->resolvePeriod($request);
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        if ($business === null) {
            return response()->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $query = CostLineItem::query()
            ->forBusiness($business->id)
            ->forPeriod($period)
            ->with(['costProvider', 'project.client']);

        $total = (clone $query)->count();

        $lines = $query
            ->orderByDesc('usd_amount')
            ->skip($start)
            ->take($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $lines->map(fn (CostLineItem $line): array => $this->presentLine($line)),
        ]);
    }

    /**
     * Re-run attribution for the period on demand.
     *
     * Ingestion and attribution are separate steps on separate schedules, so a
     * cost that has just synced — or a resource just pointed at a project —
     * sits unattributed until the nightly pass. Without this the operator has
     * to wait overnight to see their own change take effect.
     */
    public function attribute(Request $request, CostAttributor $attributor): JsonResponse
    {
        $this->authorize('viewAny-billing');

        $business = $this->currentBusiness->get();

        if ($business === null) {
            return response()->json(['success' => false, 'message' => 'No business selected.'], 422);
        }

        $period = $this->resolvePeriod($request);
        $result = $attributor->attribute($business->id, $period);

        return response()->json([
            'success' => true,
            'message' => $result->processed === 0
                ? 'No costs ingested for '.BillingPeriod::label($period).' yet.'
                : sprintf(
                    '%d cost line(s) processed for %s — %d attributed, %d still unattributed.',
                    $result->processed,
                    BillingPeriod::label($period),
                    $result->attributed,
                    $result->unattributed,
                ),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function presentLine(CostLineItem $line): array
    {
        $state = $line->attributionState();

        return [
            'provider' => e($line->costProvider->display_name),
            'description' => e($line->description),
            'category' => e($line->category->label()),
            'project' => $line->project !== null
                ? e($line->project->name)
                : '<em class="text-default-400">—</em>',
            'client' => $line->project?->client !== null
                ? e($line->project->client->name)
                : '<em class="text-default-400">—</em>',
            'state' => sprintf(
                '<span class="badge bg-%s">%s</span>',
                e($state->badgeColor()),
                e($state->label()),
            ),
            'cost' => '$'.number_format((float) $line->usdCostBasis(), 2).' USD',
        ];
    }

    private function resolvePeriod(Request $request): string
    {
        $period = (string) $request->input('period', '');

        return BillingPeriod::isValid($period) ? $period : BillingPeriod::current();
    }

    /**
     * @return array<string, string>
     */
    private function periodChoices(): array
    {
        $choices = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < self::PERIOD_CHOICES; $i++) {
            $period = $cursor->copy()->subMonths($i)->format('Y-m');
            $choices[$period] = BillingPeriod::label($period);
        }

        return $choices;
    }
}
