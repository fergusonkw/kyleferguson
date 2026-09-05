@extends('admin-v2.layouts.vertical', ['title' => 'Reconciliation'])

@php
    $money = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, 2);
@endphp

@section('content')
    <x-admin-v2.page-title
        title="Reconciliation"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Reconciliation', 'active' => true],
        ]"
    />

    @if($business === null)
        <x-admin-v2.card>
            <div class="text-center py-10">
                <i data-lucide="scale" class="size-12 mx-auto text-default-400 mb-3"></i>
                <h4 class="text-lg font-semibold">No businesses configured yet</h4>
                <p class="text-sm text-default-500 mt-1">
                    Create a business and connect a cost provider to start reconciling costs.
                </p>
            </div>
        </x-admin-v2.card>
    @else
        <x-admin-v2.card class="mb-5">
            <form method="GET" action="{{ route('admin.billing.reconciliation.index') }}"
                  class="flex flex-wrap items-end gap-4">
                <div class="grow max-w-xs">
                    <label for="period" class="block text-sm font-medium mb-1">Billing period</label>
                    <select id="period" name="period"
                            class="form-select w-full"
                            onchange="this.form.submit()">
                        @foreach($periods as $value => $label)
                            <option value="{{ $value }}" @selected($value === $period)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-sm text-default-400 pb-2">
                    Showing <span class="font-medium text-default-700">{{ $business->name }}</span> ·
                    all figures are the USD cost basis
                </div>
            </form>
        </x-admin-v2.card>

        @if($summary->needsAttention())
            <x-admin-v2.alert
                type="warning"
                message="Some costs for this period cannot be attributed yet. Anything left unattributed will not reach a client invoice."
            />
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
            <x-admin-v2.stat-card
                title="Attributed"
                icon="circle-check"
                :primaryStatistic="$money($summary->attributedCost)"
                :animateCounter="false"
                secondaryTitle="Billable to projects"
                secondaryColor="success"
            />
            <x-admin-v2.stat-card
                title="Unattributed"
                icon="circle-help"
                :primaryStatistic="$money($summary->unattributedCost)"
                :animateCounter="false"
                :secondaryTitle="$summary->unattributedResourceCount . ' resource(s) unassigned'"
                :secondaryColor="$summary->unattributedCost > 0 || $summary->unattributedResourceCount > 0 ? 'warning' : 'success'"
            />
            <x-admin-v2.stat-card
                title="Overhead"
                icon="building-2"
                :primaryStatistic="$money($summary->overheadCost)"
                :animateCounter="false"
                secondaryTitle="Absorbed, not billed on"
                secondaryColor="info"
            />
            <x-admin-v2.stat-card
                title="Cost gap"
                icon="scale"
                :primaryStatistic="$summary->hasCostGap() ? $money($summary->costGap) : '—'"
                :animateCounter="false"
                :secondaryTitle="$summary->hasCostGap() ? 'Provider total vs accounted' : 'No self-reporting provider'"
                :secondaryColor="$summary->hasCostGap() ? ($summary->costGap == 0.0 ? 'success' : 'danger') : 'default'"
            />
        </div>

        @unless($summary->hasCostGap())
            <p class="text-xs text-default-400 mt-2">
                The cost gap needs a provider that independently reports what it billed. SMTP2GO is priced
                by a fee you enter, so its ingested total and its billed total are the same figure.
            </p>
        @endunless

        @if($unattributedResources->isNotEmpty())
            <x-admin-v2.card title="Unattributed resources" class="mt-5">
                <p class="text-sm text-default-500 mb-3">
                    These resources are not assigned to a project, so their costs cannot be billed on.
                    Assign them from the
                    <a href="{{ route('admin.billing.projects.unattributed') }}" class="text-primary">unattributed resources</a>
                    view.
                </p>
                <div class="overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Type</th>
                                <th>Name</th>
                                <th class="text-center">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unattributedResources as $resource)
                                <tr>
                                    <td>{{ $resource->costProvider->display_name }}</td>
                                    <td><span class="badge bg-default">{{ $resource->resource_type }}</span></td>
                                    <td>{{ $resource->name ?? $resource->provider_resource_id }}</td>
                                    <td class="text-center text-default-400">{{ $resource->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin-v2.card>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mt-5">
            <x-admin-v2.card title="Cost by client">
                @forelse($byClient as $row)
                    <div class="flex items-center justify-between py-2 border-b border-default-200 last:border-0">
                        <span class="text-sm">{{ $row['client_name'] }}</span>
                        <span class="text-sm font-medium">{{ $money($row['cost']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-default-400 py-4 text-center">No attributed costs for this period.</p>
                @endforelse
            </x-admin-v2.card>

            <x-admin-v2.card title="Cost by project">
                @forelse($byProject as $row)
                    <div class="flex items-center justify-between py-2 border-b border-default-200 last:border-0">
                        <span class="text-sm">
                            {{ $row['project_name'] }}
                            <span class="text-default-400">· {{ $row['client_name'] }}</span>
                        </span>
                        <span class="text-sm font-medium">{{ $money($row['cost']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-default-400 py-4 text-center">No attributed costs for this period.</p>
                @endforelse
            </x-admin-v2.card>
        </div>

        <x-admin-v2.card title="Cost line items" class="mt-5">
            <x-admin-v2.datatable
                id="costLineItemsTable"
                :columns="[
                    ['title' => 'Provider', 'data' => 'provider'],
                    ['title' => 'Description', 'data' => 'description'],
                    ['title' => 'Category', 'data' => 'category'],
                    ['title' => 'Client', 'data' => 'client'],
                    ['title' => 'Project', 'data' => 'project'],
                    ['title' => 'State', 'data' => 'state', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Cost', 'data' => 'cost', 'orderable' => false, 'className' => 'text-end'],
                ]"
                ajax-url="{{ route('admin.billing.reconciliation.line-items', ['period' => $period]) }}"
                :server-side="true"
                empty-message="No costs ingested for this period yet."
            />
        </x-admin-v2.card>
    @endif
@endsection
