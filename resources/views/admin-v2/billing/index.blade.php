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

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5 mt-5">
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

    @if($reconciliation !== null)
        <div class="mt-6">
            <h5 class="text-sm font-semibold text-default-500 uppercase mb-3">
                Reconciliation — {{ $currentPeriod }}
            </h5>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                <x-admin-v2.stat-card
                    title="Unattributed Resources"
                    icon="triangle-alert"
                    :primaryStatistic="$reconciliation['unattributed_resources']"
                    secondaryTitle="{{ $reconciliation['unattributed_resources'] === 0 ? 'All clear' : 'Need project mapping' }}"
                    :secondaryColor="$reconciliation['unattributed_resources'] === 0 ? 'success' : 'danger'"
                />
                <x-admin-v2.stat-card
                    title="DO Billed (USD)"
                    icon="cloud-download"
                    :primaryStatistic="'$'.number_format($reconciliation['do_total_usd'], 2)"
                    secondaryTitle="This period"
                    secondaryColor="info"
                />
                <x-admin-v2.stat-card
                    title="Attributed (USD)"
                    icon="check-circle"
                    :primaryStatistic="'$'.number_format($reconciliation['attributed_own_usd'], 2)"
                    secondaryTitle="{{ $reconciliation['unattributed_usd'] > 0.001 ? 'Unattributed: $'.number_format($reconciliation['unattributed_usd'], 2) : 'Fully attributed' }}"
                    :secondaryColor="$reconciliation['unattributed_usd'] > 0.001 ? 'warning' : 'success'"
                />
                <x-admin-v2.stat-card
                    title="Trailing 12-Mo (USD)"
                    icon="trending-up"
                    :primaryStatistic="'$'.number_format($reconciliation['trailing_12mo_usd'], 2)"
                    secondaryTitle="Attributed costs"
                    secondaryColor="primary"
                />
            </div>
        </div>
    @endif
@endsection
