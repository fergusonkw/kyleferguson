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

    <x-admin-v2.card class="mt-5">
        <p class="text-sm text-default-500">
            Phase 1 brings the foundation: businesses, clients, projects, cost providers, and the DigitalOcean
            sync. Cost ingestion, invoice generation, and reconciliation arrive in phases 2 and 3.
        </p>
    </x-admin-v2.card>
@endsection
