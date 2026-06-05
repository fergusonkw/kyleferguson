<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs with filtering.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny-audit-logs');

        $query = AuditLog::query()
            ->with(['user', 'auditable'])
            ->orderByDesc('created_at');

        // Apply filters
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->input('auditable_type'));
        }

        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', $request->input('tag'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Critical events only filter
        if ($request->boolean('critical_only')) {
            $query->critical();
        }

        $auditLogs = $query->paginate(50)->withQueryString();

        // Get distinct events for filter dropdown
        $events = AuditLog::query()
            ->distinct()
            ->pluck('event')
            ->sort()
            ->values();

        // Get distinct auditable types for filter dropdown
        $auditableTypes = AuditLog::query()
            ->distinct()
            ->whereNotNull('auditable_type')
            ->pluck('auditable_type')
            ->map(fn ($type) => class_basename($type))
            ->sort()
            ->values();

        return view('admin-v2.audit-logs.index', compact('auditLogs', 'events', 'auditableTypes'));
    }

    /**
     * Display the specified audit log entry.
     */
    public function show(AuditLog $auditLog): View
    {
        Gate::authorize('viewAny-audit-logs');

        $auditLog->load(['user', 'auditable']);

        return view('admin-v2.audit-logs.show', compact('auditLog'));
    }
}
