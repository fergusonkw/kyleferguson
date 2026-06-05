<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Business;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\View\View;

final class BillingDashboardController extends Controller
{
    public function __construct(private readonly ReconciliationReporter $reporter) {}

    public function index(): View
    {
        $this->authorize('viewAny-billing');

        $currentPeriod = now()->format('Y-m');
        $currentBusiness = session('billing_business_id')
            ? Business::find(session('billing_business_id'))
            : Business::query()->first();

        $reconciliation = null;

        if ($currentBusiness !== null) {
            $costGap = $this->reporter->costGap($currentBusiness, $currentPeriod);
            $trailing = $this->reporter->trailing12MonthCosts($currentBusiness);

            $reconciliation = [
                'unattributed_resources' => $this->reporter->unattributedResourceCount($currentBusiness),
                'do_total_usd' => $costGap['do_total_usd'],
                'attributed_usd' => $costGap['attributed_usd'],
                'gap_usd' => $costGap['gap_usd'],
                'trailing_12mo_usd' => $trailing['total_usd'],
                'period' => $currentPeriod,
            ];
        }

        return view('admin-v2.billing.index', compact('reconciliation', 'currentPeriod'));
    }
}
