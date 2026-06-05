<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
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

        return view('admin-v2.index', compact(
            'totalUsers',
            'disabledUsers',
            'lockedUsers',
            'totalRoles',
            'auditLogCount',
            'pendingJobs',
            'failedJobs',
            'recentAuditLogs',
        ));
    }
}
