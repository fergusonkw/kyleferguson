@extends('admin-v2.layouts.vertical', ['title' => 'Billing'])

@section('content')
    <x-admin-v2.page-title title="Billing" :breadcrumbs="[['label' => 'Billing', 'active' => true]]" />

    @if(session('status'))
        <x-admin-v2.alert type="success" :message="session('status')" dismissible />
    @endif

    @isset($currentBusiness)
        <x-admin-v2.card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-default-400 uppercase">Current business</p>
                    <h4 class="text-lg font-semibold mt-1">{{ $currentBusiness->name }}</h4>
                    @if($currentBusiness->legal_name && $currentBusiness->legal_name !== $currentBusiness->name)
                        <p class="text-sm text-default-500">{{ $currentBusiness->legal_name }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-xs text-default-400 uppercase">Default currency</p>
                    <p class="text-sm font-medium mt-1">{{ $currentBusiness->default_currency }}</p>
                </div>
            </div>
        </x-admin-v2.card>
    @else
        <x-admin-v2.card>
            <div class="text-center py-10">
                <i data-lucide="briefcase-business" class="size-12 mx-auto text-default-400 mb-3"></i>
                <h4 class="text-lg font-semibold">No businesses configured yet</h4>
                <p class="text-sm text-default-500 mt-1">
                    Create your first business entity to start tracking billing.
                </p>
            </div>
        </x-admin-v2.card>
    @endisset

    @isset($receivables)
        <div class="flex items-center justify-between mt-8 mb-3">
            <h5 class="text-sm font-semibold text-default-500 uppercase">Money owed</h5>
            <a href="{{ route('admin.billing.receivables.index') }}" class="text-sm text-primary">Receivables →</a>
        </div>

        @if($receivables->hasOverdue())
            <x-admin-v2.alert
                type="danger"
                message="{{ $receivables->overdueCount }} invoice(s) are past due, the oldest by {{ $receivables->oldestOverdueDays }} days."
            />
        @elseif($receivables->awaitingSendCount > 0)
            <x-admin-v2.alert
                type="warning"
                message="{{ $receivables->awaitingSendCount }} approved invoice(s) have not been sent to the client yet."
            />
        @endif

        <x-admin-v2.billing.receivable-cards :summary="$receivables" />
    @endisset

    <div class="flex items-center justify-between mt-8 mb-3">
        <h5 class="text-sm font-semibold text-default-500 uppercase">Configuration</h5>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
        <x-admin-v2.stat-card
            title="Businesses"
            icon="briefcase-business"
            :primaryStatistic="\App\Models\Billing\Business::count()"
            secondaryTitle="Entities"
            secondaryColor="primary"
        />
        <x-admin-v2.stat-card
            title="Clients"
            icon="users"
            :primaryStatistic="\App\Models\Billing\Client::count()"
            secondaryTitle="All businesses"
            secondaryColor="info"
        />
        <x-admin-v2.stat-card
            title="Projects"
            icon="folder-kanban"
            :primaryStatistic="\App\Models\Billing\Project::count()"
            secondaryTitle="Active and otherwise"
            secondaryColor="success"
        />
        <x-admin-v2.stat-card
            title="Cost providers"
            icon="cloud"
            :primaryStatistic="\App\Models\Billing\CostProvider::count()"
            secondaryTitle="Configured"
            secondaryColor="warning"
        />
    </div>

    @isset($summary)
        @php
            $money = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, 2);
        @endphp

        <div class="flex items-center justify-between mt-8 mb-3">
            <h5 class="text-sm font-semibold text-default-500 uppercase">Costs · {{ $periodLabel }}</h5>
            <a href="{{ route('admin.billing.reconciliation.index', ['period' => $period]) }}"
               class="text-sm text-primary">Reconciliation →</a>
        </div>

        @if($summary->needsAttention())
            <x-admin-v2.alert
                type="warning"
                message="Some costs this period are not attributed to a project yet and will not reach a client invoice."
            />
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
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
                :secondaryColor="$summary->unattributedResourceCount > 0 ? 'warning' : 'success'"
            />
            <x-admin-v2.stat-card
                title="Overhead"
                icon="building-2"
                :primaryStatistic="$money($summary->overheadCost)"
                :animateCounter="false"
                secondaryTitle="Absorbed, not billed on"
                secondaryColor="info"
            />
        </div>

        @if($trailingCost->isNotEmpty())
            <x-admin-v2.card title="Trailing 12-month cost" class="mt-5">
                <p class="text-sm text-default-500 mb-4">
                    Ingested cost basis per period. This is cost, not revenue — the trailing-revenue
                    gauge against the $30K registration threshold still needs a decision on how
                    invoices issued in other currencies count toward a CAD threshold.
                </p>
                <div class="overflow-x-auto">
                    <div class="flex items-end gap-2 min-w-[480px] h-32">
                        @php($peak = max($trailingCost->max('cost'), 0.01))
                        @foreach($trailingCost as $month)
                            <div class="flex-1 flex flex-col items-center justify-end h-full gap-1">
                                <span class="text-[10px] text-default-400">{{ $month['cost'] > 0 ? number_format($month['cost'], 0) : '' }}</span>
                                <div class="w-full bg-primary/70 rounded-t"
                                     style="height: {{ max(2, (int) round(($month['cost'] / $peak) * 90)) }}%"
                                     title="{{ $month['period'] }}: {{ $money($month['cost']) }}"></div>
                                <span class="text-[10px] text-default-400">{{ substr($month['period'], 5) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-admin-v2.card>
        @endif
    @endisset
@endsection
