<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Business;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationReporter $reporter) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny-billing');

        $currentBusiness = session('billing_business_id')
            ? Business::find(session('billing_business_id'))
            : Business::query()->first();

        $period = $request->string('period', now()->format('Y-m'))->toString();

        $data = null;

        if ($currentBusiness !== null) {
            $costGap = $this->reporter->costGap($currentBusiness, $period);
            $trailing = $this->reporter->trailing12MonthCosts($currentBusiness);
            $thresholdStatus = $this->reporter->trailingThresholdStatus($currentBusiness);

            $unattributedResources = ProviderResource::query()
                ->with(['costProvider', 'currentAssignment'])
                ->whereNull('project_id')
                ->whereHas('costProvider', fn ($q) => $q->where('business_id', $currentBusiness->id))
                ->orderByDesc('last_seen_at')
                ->get();

            $unattributedLineItems = CostLineItem::query()
                ->with(['costProvider', 'sourcePayload'])
                ->where('period', $period)
                ->whereNull('project_id')
                ->whereHas('costProvider', fn ($q) => $q->where('business_id', $currentBusiness->id))
                ->orderBy('usd_amount', 'desc')
                ->get();

            $data = [
                'cost_gap' => $costGap,
                'trailing' => $trailing,
                'threshold_status' => $thresholdStatus,
                'unattributed_resources' => $unattributedResources,
                'unattributed_line_items' => $unattributedLineItems,
                'period' => $period,
            ];
        }

        return view('admin-v2.billing.reconciliation.index', [
            'currentBusiness' => $currentBusiness,
            'data' => $data,
            'period' => $period,
        ]);
    }
}
