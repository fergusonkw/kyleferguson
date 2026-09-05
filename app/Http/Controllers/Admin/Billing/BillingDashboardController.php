<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\ReceivablesReporter;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\View\View;

final class BillingDashboardController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ReconciliationReporter $reporter,
        private readonly ReceivablesReporter $receivables,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny-billing');

        $business = $this->currentBusiness->get();
        $period = BillingPeriod::current();

        return view('admin-v2.billing.index', [
            'currentBusiness' => $business,
            'period' => $period,
            'periodLabel' => BillingPeriod::label($period),
            'summary' => $business !== null
                ? $this->reporter->summarize($business->id, $period)
                : null,
            'trailingCost' => $business !== null
                ? $this->reporter->trailingCost($business->id)
                : collect(),
            'receivables' => $business !== null
                ? $this->receivables->summarize($business)
                : null,
        ]);
    }
}
