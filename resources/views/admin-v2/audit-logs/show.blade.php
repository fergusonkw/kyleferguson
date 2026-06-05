@extends('admin-v2.layouts.vertical', ['title' => 'Audit Log #' . $auditLog->id])

@section('content')
<x-admin-v2.page-title
    title="Audit Log Details"
    :breadcrumbs="[
        ['label' => 'Audit Logs', 'url' => route('admin.audit-logs.index')],
        ['label' => 'Log #' . $auditLog->id, 'active' => true],
    ]"
/>

<x-admin-v2.card title="Audit Log #{{ $auditLog->id }}">
    <x-slot:headerActions>
        <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-light btn-sm">
            <i data-lucide="arrow-left" class="size-4 me-1"></i> Back to List
        </a>
    </x-slot:headerActions>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-6">
        <div>
            <h6 class="text-xs font-semibold text-default-500 uppercase mb-3">Event Information</h6>

            <div class="mb-3">
                <p class="text-xs font-semibold mb-1">Event Type</p>
                <span class="badge bg-info/15 text-info">
                    {{ str_replace('_', ' ', ucwords($auditLog->event, '_')) }}
                </span>
            </div>

            <div class="mb-3">
                <p class="text-xs font-semibold mb-1">Timestamp</p>
                <p class="text-sm">{{ $auditLog->created_at->format('F d, Y \a\t g:i:s A') }}</p>
                <p class="text-xs text-default-400">{{ $auditLog->created_at->diffForHumans() }}</p>
            </div>

            <div class="mb-3">
                <p class="text-xs font-semibold mb-1">User</p>
                @if($auditLog->user)
                    <p class="text-sm">{{ $auditLog->user->name }}</p>
                    <p class="text-xs text-default-400">{{ $auditLog->user->email }}</p>
                @else
                    <span class="text-default-400 text-sm">System / Automated</span>
                @endif
            </div>

            @if($auditLog->tags && count($auditLog->tags) > 0)
                <div class="mb-3">
                    <p class="text-xs font-semibold mb-1">Tags</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($auditLog->tags as $tag)
                            <span class="badge
                                {{ $tag === 'critical' ? 'bg-danger' : '' }}
                                {{ $tag === 'security' ? 'bg-warning' : '' }}
                                {{ $tag === 'points' ? 'bg-success' : '' }}
                                {{ $tag === 'official' ? 'bg-primary' : '' }}
                                {{ !in_array($tag, ['critical','security','points','official']) ? 'bg-secondary' : '' }}">
                                {{ $tag }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div>
            <h6 class="text-xs font-semibold text-default-500 uppercase mb-3">Context Information</h6>

            @if($auditLog->auditable_type)
                <div class="mb-3">
                    <p class="text-xs font-semibold mb-1">Model Type</p>
                    <p class="text-sm">
                        {{ class_basename($auditLog->auditable_type) }}
                        @if($auditLog->auditable_id)
                            <span class="text-default-400">#{{ $auditLog->auditable_id }}</span>
                        @endif
                    </p>
                </div>
            @endif

            @if($auditLog->auditable)
                <div class="mb-3">
                    <p class="text-xs font-semibold mb-1">Related Record</p>
                    <p class="text-sm">
                        @if(method_exists($auditLog->auditable, 'getAuditDescription'))
                            {{ $auditLog->auditable->getAuditDescription() }}
                        @elseif(isset($auditLog->auditable->name))
                            {{ $auditLog->auditable->name }}
                        @elseif(isset($auditLog->auditable->event_name))
                            {{ $auditLog->auditable->event_name }}
                        @else
                            {{ class_basename($auditLog->auditable_type) }}
                        @endif
                    </p>
                </div>
            @endif

            <div class="mb-3">
                <p class="text-xs font-semibold mb-1">IP Address</p>
                <code class="text-sm">{{ $auditLog->ip_address ?? 'N/A' }}</code>
            </div>

            @if($auditLog->user_agent)
                <div class="mb-3">
                    <p class="text-xs font-semibold mb-1">User Agent</p>
                    <p class="text-xs text-default-400 break-words">{{ $auditLog->user_agent }}</p>
                </div>
            @endif
        </div>
    </div>

    @if($auditLog->old_values || $auditLog->new_values)
        <hr class="border-default-200 mb-5">
        <h6 class="text-xs font-semibold text-default-500 uppercase mb-3">Changes</h6>

        <div class="overflow-x-auto">
            <table class="table w-full text-sm">
                <thead>
                    <tr>
                        <th class="w-1/4">Field</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $oldValues = $auditLog->old_values ?? [];
                        $newValues = $auditLog->new_values ?? [];
                        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
                    @endphp

                    @forelse($allKeys as $key)
                        <tr>
                            <td class="font-medium">{{ ucwords(str_replace('_', ' ', $key)) }}</td>
                            <td>
                                @if(isset($oldValues[$key]))
                                    @if(is_array($oldValues[$key]))
                                        <pre class="text-xs bg-default-100 p-2 rounded"><code>{{ json_encode($oldValues[$key], JSON_PRETTY_PRINT) }}</code></pre>
                                    @elseif(is_bool($oldValues[$key]))
                                        <span class="badge {{ $oldValues[$key] ? 'bg-success' : 'bg-danger' }}">{{ $oldValues[$key] ? 'Yes' : 'No' }}</span>
                                    @elseif(is_null($oldValues[$key]))
                                        <span class="text-default-400 italic">null</span>
                                    @else
                                        <code class="text-xs">{{ $oldValues[$key] }}</code>
                                    @endif
                                @else
                                    <span class="text-default-400">—</span>
                                @endif
                            </td>
                            <td>
                                @if(isset($newValues[$key]))
                                    @if(is_array($newValues[$key]))
                                        <pre class="text-xs bg-default-100 p-2 rounded"><code>{{ json_encode($newValues[$key], JSON_PRETTY_PRINT) }}</code></pre>
                                    @elseif(is_bool($newValues[$key]))
                                        <span class="badge {{ $newValues[$key] ? 'bg-success' : 'bg-danger' }}">{{ $newValues[$key] ? 'Yes' : 'No' }}</span>
                                    @elseif(is_null($newValues[$key]))
                                        <span class="text-default-400 italic">null</span>
                                    @else
                                        <code class="text-xs">{{ $newValues[$key] }}</code>
                                    @endif
                                @else
                                    <span class="text-default-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-default-400">No field changes recorded</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if(auth()->user()->isSuperAdmin())
        <hr class="border-default-200 mt-5 mb-4">
        <h6 class="text-xs font-semibold text-default-500 uppercase mb-3">Raw Audit Data (Super Admins Only)</h6>
        <details>
            <summary class="btn btn-xs btn-light mb-2 cursor-pointer">View Raw JSON Data</summary>
            <pre class="bg-default-100 p-3 rounded text-xs mt-2 overflow-x-auto"><code>{{ json_encode([
                'id' => $auditLog->id,
                'user_id' => $auditLog->user_id,
                'event' => $auditLog->event,
                'auditable_type' => $auditLog->auditable_type,
                'auditable_id' => $auditLog->auditable_id,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
                'tags' => $auditLog->tags,
                'created_at' => $auditLog->created_at->toIso8601String(),
            ], JSON_PRETTY_PRINT) }}</code></pre>
        </details>
    @endif
</x-admin-v2.card>
@endsection
