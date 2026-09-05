<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\Dto\ReceivablesSummary;
use App\Services\Billing\ReceivablesReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly ReceivablesReporter $receivables,
    ) {}

    public function index(Request $request): View
    {
        $totalUsers = User::query()->count();
        $disabledUsers = User::query()->where('is_disabled', true)->count();
        $lockedUsers = User::query()->where('is_locked', true)->count();
        $totalRoles = Role::query()->count();
        $auditLogCount = AuditLog::query()->count();

        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $recentAuditLogs = AuditLog::query()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();

        $receivables = $this->receivablesFor($request);

        return view('admin-v2.index', compact(
            'totalUsers',
            'disabledUsers',
            'lockedUsers',
            'totalRoles',
            'auditLogCount',
            'pendingJobs',
            'failedJobs',
            'recentAuditLogs',
            'receivables',
        ));
    }

    /**
     * The receivable figures, or null for anyone who cannot see billing.
     *
     * This is the landing page, so what is owed belongs here — but it is money,
     * and an admin without the billing permission has no business reading it
     * from a dashboard they were never gated out of.
     */
    private function receivablesFor(Request $request): ?ReceivablesSummary
    {
        $user = $request->user();

        if ($user === null || $user->cannot('viewAny-billing')) {
            return null;
        }

        $business = $this->currentBusiness->get();

        return $business === null ? null : $this->receivables->summarize($business);
    }
}
