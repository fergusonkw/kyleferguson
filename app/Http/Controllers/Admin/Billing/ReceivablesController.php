<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Invoice;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\ReceivablesReporter;
use Illuminate\View\View;

final class ReceivablesController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ReceivablesReporter $receivables,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Invoice::class);

        $business = $this->currentBusiness->get();

        if ($business === null) {
            return view('admin-v2.billing.receivables.index', [
                'currentBusiness' => null,
                'summary' => null,
                'aging' => [],
                'outstanding' => collect(),
                'payments' => collect(),
                'reporter' => $this->receivables,
            ]);
        }

        return view('admin-v2.billing.receivables.index', [
            'currentBusiness' => $business,
            'summary' => $this->receivables->summarize($business),
            'aging' => $this->receivables->aging($business->id),
            'outstanding' => $this->receivables->outstandingInvoices($business->id),
            'payments' => $this->receivables->recentPayments($business->id),

            // The view asks the reporter how overdue each row is rather than
            // recomputing the rule in Blade, so the list and the buckets can
            // never disagree about what "late" means.
            'reporter' => $this->receivables,
        ]);
    }
}
