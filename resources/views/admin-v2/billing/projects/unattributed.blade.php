@extends('admin-v2.layouts.vertical', ['title' => 'Unattributed Resources'])

@section('content')
    <x-admin-v2.page-title
        title="Unattributed Resources"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Projects', 'url' => route('admin.billing.projects.index')],
            ['label' => 'Unattributed', 'active' => true],
        ]"
    />

    <x-admin-v2.alert
        type="info"
        message="Resources here belong to a DigitalOcean project that has not yet been linked to a local project — typically because they sit in the DO Default Project or were spun up before being assigned. Link the DO project on the matching local project to start attributing their costs."
    />

    <x-admin-v2.card title="Unattributed Resources">
        <x-admin-v2.datatable
            id="unattributedTable"
            :columns="[
                ['title' => 'Provider', 'data' => 'provider'],
                ['title' => 'Type', 'data' => 'type'],
                ['title' => 'Name', 'data' => 'name'],
                ['title' => 'DO Project', 'data' => 'do_project_name'],
                ['title' => 'Flag', 'data' => 'is_default', 'orderable' => false, 'className' => 'text-center'],
                ['title' => 'Last Seen', 'data' => 'last_seen_at', 'orderable' => false, 'className' => 'text-center'],
            ]"
            ajax-url="{{ route('admin.billing.projects.unattributed') }}"
            :server-side="true"
        />
    </x-admin-v2.card>
@endsection
