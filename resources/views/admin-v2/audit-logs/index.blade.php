@extends('admin-v2.layouts.vertical', ['title' => 'Audit Logs'])

@section('content')
<x-admin-v2.page-title
    title="Audit Logs"
    :breadcrumbs="[['label' => 'Audit Logs', 'active' => true]]"
/>

<x-admin-v2.card title="Activity Audit Trail">
    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="mb-5">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-3">
            <div>
                <label for="event" class="form-label text-xs">Event Type</label>
                <select name="event" id="event" class="form-select form-select-sm">
                    <option value="">All Events</option>
                    @foreach($events as $eventType)
                        <option value="{{ $eventType }}" {{ request('event') === $eventType ? 'selected' : '' }}>
                            {{ str_replace('_', ' ', ucwords($eventType, '_')) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="auditable_type" class="form-label text-xs">Model Type</label>
                <select name="auditable_type" id="auditable_type" class="form-select form-select-sm">
                    <option value="">All Models</option>
                    @foreach($auditableTypes as $type)
                        <option value="{{ $type }}" {{ request('auditable_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tag" class="form-label text-xs">Tag</label>
                <select name="tag" id="tag" class="form-select form-select-sm">
                    <option value="">All Tags</option>
                    <option value="critical" {{ request('tag') === 'critical' ? 'selected' : '' }}>Critical</option>
                    <option value="security" {{ request('tag') === 'security' ? 'selected' : '' }}>Security</option>
                    <option value="points" {{ request('tag') === 'points' ? 'selected' : '' }}>Points</option>
                    <option value="official" {{ request('tag') === 'official' ? 'selected' : '' }}>Official</option>
                    <option value="organization" {{ request('tag') === 'organization' ? 'selected' : '' }}>Organization</option>
                </select>
            </div>
            <div>
                <label for="date_from" class="form-label text-xs">Date From</label>
                <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>
            <div>
                <label for="date_to" class="form-label text-xs">Date To</label>
                <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>
        </div>

        <div class="flex items-center gap-4 mb-3">
            <div class="flex items-center gap-2">
                <input class="form-checkbox form-checkbox-light size-4" type="checkbox"
                       name="critical_only" id="critical_only" value="1"
                       {{ request('critical_only') ? 'checked' : '' }}>
                <label class="text-sm" for="critical_only">Show Critical Events Only</label>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
                <i data-lucide="filter" class="size-4 me-1"></i> Apply Filters
            </button>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-light btn-sm">
                <i data-lucide="x" class="size-4 me-1"></i> Clear Filters
            </a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="table w-full text-sm">
            <thead>
                <tr>
                    <th class="w-16">ID</th>
                    <th class="w-36">Timestamp</th>
                    <th class="w-28">User</th>
                    <th class="w-44">Event</th>
                    <th class="w-28">Model</th>
                    <th>Description</th>
                    <th class="w-24">Tags</th>
                    <th class="text-center w-20">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($auditLogs as $log)
                    <tr class="{{ in_array('critical', $log->tags ?? []) ? 'bg-warning/10' : '' }}">
                        <td class="text-default-400 text-xs">#{{ $log->id }}</td>
                        <td class="text-xs text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="text-xs">
                            @if($log->user)
                                {{ $log->user->name }}
                            @else
                                <span class="text-default-400">System</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-info/15 text-info">
                                {{ str_replace('_', ' ', ucwords($log->event, '_')) }}
                            </span>
                        </td>
                        <td class="text-xs">
                            @if($log->auditable_type)
                                {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                            @else
                                <span class="text-default-400">N/A</span>
                            @endif
                        </td>
                        <td class="text-xs">
                            @if($log->auditable)
                                @if(method_exists($log->auditable, 'getAuditDescription'))
                                    {{ $log->auditable->getAuditDescription() }}
                                @elseif(isset($log->auditable->name))
                                    {{ $log->auditable->name }}
                                @elseif(isset($log->auditable->event_name))
                                    {{ $log->auditable->event_name }}
                                @else
                                    {{ class_basename($log->auditable_type) }} modified
                                @endif
                            @else
                                <span class="text-default-400">
                                    @if($log->old_values && is_array($log->old_values))
                                        {{ count($log->old_values) }} fields changed
                                    @elseif($log->new_values && is_array($log->new_values))
                                        {{ count($log->new_values) }} fields updated
                                    @else
                                        System operation
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($log->tags)
                                <div class="flex flex-wrap gap-1">
                                    @foreach($log->tags as $tag)
                                        <span class="badge
                                            {{ $tag === 'critical' ? 'bg-danger' : '' }}
                                            {{ $tag === 'security' ? 'bg-warning' : '' }}
                                            {{ $tag === 'points' ? 'bg-success' : '' }}
                                            {{ $tag === 'official' ? 'bg-primary' : '' }}
                                            {{ !in_array($tag, ['critical','security','points','official']) ? 'bg-secondary' : '' }}
                                            text-xs">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="text-center">
                            <a href="{{ route('admin.audit-logs.show', $log) }}"
                               class="btn btn-xs btn-light" title="View Details">
                                <i data-lucide="eye" class="size-3"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-default-400 py-8">
                            <i data-lucide="file-search" class="size-8 mx-auto mb-2 opacity-40"></i>
                            <p>No audit logs found matching the current filters.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $auditLogs->links() }}</div>

    <div class="border-t border-default-200 mt-4 pt-3 flex justify-between text-xs text-default-400">
        <span>Showing {{ $auditLogs->firstItem() ?? 0 }} to {{ $auditLogs->lastItem() ?? 0 }} of {{ $auditLogs->total() }} entries</span>
        <span>Page {{ $auditLogs->currentPage() }} of {{ $auditLogs->lastPage() }}</span>
    </div>
</x-admin-v2.card>
@endsection
