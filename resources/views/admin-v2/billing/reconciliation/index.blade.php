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
                <div class="text-sm text-default-400 pb-2 grow">
                    Showing <span class="font-medium text-default-700">{{ $business->name }}</span> ·
                    all figures are the USD cost basis
                </div>
                <button type="button" class="btn btn-light mb-1" id="attributeBtn">
                    <i data-lucide="refresh-cw" class="size-4 me-1"></i> Re-attribute costs
                </button>
            </form>
            <p class="text-xs text-default-400 mt-2">
                Ingested costs are matched to projects nightly. Run it now after connecting a provider or
                pointing a resource at a project — an unattributed cost cannot reach an invoice.
            </p>
        </x-admin-v2.card>

        @if($summary->needsAttention())
            <x-admin-v2.alert
                type="warning"
                message="Some costs for this period cannot be attributed yet. Anything left unattributed will not reach a client invoice."
            />
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-5">
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
                title="Not invoiced"
                icon="receipt"
                :primaryStatistic="$money($summary->uninvoicedCost)"
                :animateCounter="false"
                secondaryTitle="Attributed, nobody charged yet"
                :secondaryColor="$summary->uninvoicedCost > 0 ? 'warning' : 'success'"
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
                    ['title' => 'Billed', 'data' => 'billed', 'orderable' => false, 'className' => 'text-center'],
                    ['title' => 'Cost', 'data' => 'cost', 'orderable' => false, 'className' => 'text-end'],
                ]"
                ajax-url="{{ route('admin.billing.reconciliation.line-items', ['period' => $period]) }}"
                :server-side="true"
                empty-message="No costs ingested for this period yet."
            />
        </x-admin-v2.card>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('attributeBtn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const period = document.getElementById('period')?.value ?? '';
        const label = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Attributing...';

        try {
            const body = new FormData();
            body.append('period', period);

            const r = await fetch(@json(route('admin.billing.reconciliation.attribute'), JSON_UNESCAPED_SLASHES), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body,
            });
            const data = await r.json();

            if (data.success) {
                Alert.toast(data.message, 'success');
                setTimeout(() => window.location.reload(), 900);
            } else {
                Alert.error(data.message || 'Could not attribute costs.');
            }
        } catch {
            Alert.error('Could not attribute costs.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = label;
        }
    });
});
</script>
@endpush
