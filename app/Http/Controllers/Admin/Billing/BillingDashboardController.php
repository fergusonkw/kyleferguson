<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class BillingDashboardController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny-billing');

        return view('admin-v2.billing.index');
    }
}
