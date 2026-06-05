<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Business;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BusinessSwitcherController extends Controller
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function switch(Business $business, Request $request): RedirectResponse
    {
        $this->authorize('viewAny-billing');

        $this->currentBusiness->set($business);

        return back()->with('status', "Switched to {$business->name}.");
    }
}
