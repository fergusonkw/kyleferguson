@extends('admin-v2.layouts.vertical', ['title' => 'Dashboard'])

@section('content')
    <x-admin-v2.page-title title="Dashboard" :breadcrumbs="[]" />

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5 mb-5">
        <x-admin-v2.stat-card
            title="Total Users"
            icon="users"
            :primaryStatistic="$totalUsers"
            secondaryTitle="Registered accounts"
            secondaryColor="primary"
        />
        <x-admin-v2.stat-card
            title="Roles"
            icon="shield-check"
            :primaryStatistic="$totalRoles"
            secondaryTitle="Defined roles"
            secondaryColor="info"
        />
        <x-admin-v2.stat-card
            title="Disabled / Locked"
            icon="lock"
            :primaryStatistic="$disabledUsers + $lockedUsers"
            secondaryTitle="Restricted accounts"
            secondaryColor="warning"
            :detailStatistic="$lockedUsers . ' locked'"
        />
        <x-admin-v2.stat-card
            title="Audit Events"
            icon="file-bar-chart-2"
            :primaryStatistic="$auditLogCount"
            secondaryTitle="Logged actions"
            secondaryColor="success"
        />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
        <x-admin-v2.stat-card
            title="Pending Jobs"
            icon="timer"
            :primaryStatistic="$pendingJobs"
            secondaryTitle="Queued"
            secondaryColor="info"
        />
        <x-admin-v2.stat-card
            title="Failed Jobs"
            icon="triangle-alert"
            :primaryStatistic="$failedJobs"
            secondaryTitle="Need attention"
            secondaryColor="danger"
        />
    </div>

    <x-admin-v2.card title="Recent Activity">
        @if($recentAuditLogs->isEmpty())
            <p class="text-default-400 text-sm">No audit activity recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>User</th>
                            <th>Subject</th>
                            <th class="text-end">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentAuditLogs as $log)
                            <tr>
                                <td><span class="badge bg-primary">{{ $log->event }}</span></td>
                                <td>{{ $log->user?->name ?? 'System' }}</td>
                                <td>{{ $log->auditable_type ? class_basename($log->auditable_type) : '—' }}</td>
                                <td class="text-end text-default-400">{{ $log->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin-v2.card>
@endsection
