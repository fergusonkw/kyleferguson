<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnableMaintenanceModeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class MaintenanceController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny-maintenance');

        $isDown = app()->isDownForMaintenance();
        $maintenanceData = $isDown ? app()->maintenanceMode()->data() : [];

        return view('admin-v2.maintenance.index', compact('isDown', 'maintenanceData'));
    }

    public function enable(EnableMaintenanceModeRequest $request): RedirectResponse
    {
        Gate::authorize('viewAny-maintenance');

        $payload = array_filter([
            'message' => $request->input('message'),
            'retry' => $request->integer('retry_after') ?: null,
        ]);

        app()->maintenanceMode()->activate($payload);

        return redirect()->route('admin.maintenance.index')
            ->with('success', 'System has been placed into maintenance mode.');
    }

    public function disable(): RedirectResponse
    {
        Gate::authorize('viewAny-maintenance');

        app()->maintenanceMode()->deactivate();

        return redirect()->route('admin.maintenance.index')
            ->with('success', 'System has been taken out of maintenance mode.');
    }
}
