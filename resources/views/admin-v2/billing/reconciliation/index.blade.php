@extends('admin-v2.layouts.vertical', ['title' => 'Reconciliation'])

@section('content')
    <x-admin-v2.page-title
        title="Reconciliation"
        :breadcrumbs="[['label' => 'Billing', 'url' => route('admin.billing.index')], ['label' => 'Reconciliation', 'active' => true]]"
    />

    @if($currentBusiness === null)
        <x-admin-v2.alert type="warning" message="Create a business first to view reconciliation data." />
    @elseif($data === null)
        <x-admin-v2.alert type="info" message="No reconciliation data available yet. Run a billing sync to populate cost data." />
    @else
        <div class="flex items-center gap-3 mb-5">
            <form method="GET" action="{{ route('admin.billing.reconciliation.index') }}" class="flex items-center gap-2">
                <label class="text-sm font-medium text-default-600">Period</label>
                <input type="month" name="period" value="{{ $period }}" class="form-input w-40 text-sm" />
                <button type="submit" class="btn btn-light btn-sm">Go</button>
            </form>
        </div>

        {{-- Cost gap summary --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
            <x-admin-v2.stat-card
                title="DO Billed (USD)"
                icon="cloud-download"
                :primaryStatistic="'$'.number_format($data['cost_gap']['do_total_usd'], 2)"
                secondaryTitle="Total for period"
                secondaryColor="info"
            />
            <x-admin-v2.stat-card
                title="Attributed (USD)"
                icon="check-circle"
                :primaryStatistic="'$'.number_format($data['cost_gap']['attributed_usd'], 2)"
                secondaryTitle="Mapped to projects"
                secondaryColor="success"
            />
            <x-admin-v2.stat-card
                title="Cost Gap (USD)"
                icon="circle-alert"
                :primaryStatistic="'$'.number_format($data['cost_gap']['gap_usd'], 2)"
                :secondaryTitle="$data['cost_gap']['gap_usd'] > 0.001 ? 'Needs attention' : 'Fully attributed'"
                :secondaryColor="$data['cost_gap']['gap_usd'] > 0.001 ? 'danger' : 'success'"
            />
        </div>

        {{-- Unattributed resources --}}
        <x-admin-v2.card title="Unattributed Resources" class="mb-5">
            @if($data['unattributed_resources']->isEmpty())
                <p class="text-sm text-default-500 py-4 text-center">All resources are mapped to projects.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Resource ID</th>
                                <th>Type</th>
                                <th>Name</th>
                                <th>DO Project</th>
                                <th>Provider</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['unattributed_resources'] as $resource)
                                <tr>
                                    <td class="font-mono text-xs">{{ $resource->provider_resource_id }}</td>
                                    <td><span class="badge bg-default">{{ $resource->resource_type }}</span></td>
                                    <td>{{ $resource->name ?? '—' }}</td>
                                    <td class="text-xs text-default-500">{{ $resource->provider_project_uuid ?? '—' }}</td>
                                    <td>{{ $resource->costProvider->display_name ?? '—' }}</td>
                                    <td class="text-xs text-default-400">{{ $resource->last_seen_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin-v2.card>

        {{-- Unattributed cost line items --}}
        <x-admin-v2.card title="Unattributed Cost Line Items — {{ $period }}" class="mb-5">
            @if($data['unattributed_line_items']->isEmpty())
                <p class="text-sm text-default-500 py-4 text-center">All cost line items are attributed for this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Description</th>
                                <th class="text-end">Amount (USD)</th>
                                <th class="text-end">Tax (USD)</th>
                                <th>Provider</th>
                                <th>Fetched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['unattributed_line_items'] as $item)
                                <tr>
                                    <td><span class="badge bg-warning">{{ $item->category->label() }}</span></td>
                                    <td class="text-sm">{{ $item->description ?? '—' }}</td>
                                    <td class="text-end font-mono text-sm">${{ number_format($item->usd_amount, 2) }}</td>
                                    <td class="text-end font-mono text-sm text-default-400">${{ number_format($item->usd_tax, 2) }}</td>
                                    <td>{{ $item->costProvider->display_name ?? '—' }}</td>
                                    <td class="text-xs text-default-400">{{ $item->sourcePayload->fetched_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin-v2.card>

        {{-- Trailing 12-month costs --}}
        <x-admin-v2.card title="Trailing 12-Month Attributed Costs (USD)">
            <div class="flex items-end gap-1">
                <span class="text-3xl font-bold">${{ number_format($data['trailing']['total_usd'], 2) }}</span>
                <span class="text-sm text-default-400 mb-1">across {{ count($data['trailing']['periods']) }} periods</span>
            </div>
            <p class="text-xs text-default-400 mt-1">
                Periods: {{ implode(', ', $data['trailing']['periods']) }}
            </p>
        </x-admin-v2.card>
    @endif
@endsection
