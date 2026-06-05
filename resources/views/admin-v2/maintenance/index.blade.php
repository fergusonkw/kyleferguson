@extends('admin-v2.layouts.vertical', ['title' => 'Maintenance Mode'])

@section('content')
<x-admin-v2.page-title
    title="Maintenance Mode"
    :breadcrumbs="[['label' => 'Maintenance Mode', 'active' => true]]"
/>

@if(session('success'))
    <x-admin-v2.alert type="success" class="mb-5">{{ session('success') }}</x-admin-v2.alert>
@endif

<div class="max-w-2xl mx-auto space-y-5">

    {{-- Current Status --}}
    <x-admin-v2.card>
        <div class="text-center py-4">
            @if($isDown)
                <i data-lucide="wrench" class="size-12 text-warning mx-auto mb-3"></i>
                <h4 class="text-lg font-semibold mb-2">System is in Maintenance Mode</h4>
                <p class="text-default-500 text-sm mb-3">The API health endpoint is reporting <code>503 maintenance</code>. Consuming services should be pausing requests.</p>
                @if(!empty($maintenanceData['message']))
                    <x-admin-v2.alert type="warning" class="text-start">
                        <strong>Message:</strong> {{ $maintenanceData['message'] }}
                    </x-admin-v2.alert>
                @endif
                @if(!empty($maintenanceData['retry']))
                    <p class="text-default-400 text-sm mt-2">Retry hint: {{ $maintenanceData['retry'] }} seconds</p>
                @endif
            @else
                <i data-lucide="circle-check" class="size-12 text-success mx-auto mb-3"></i>
                <h4 class="text-lg font-semibold mb-2">System is Live</h4>
                <p class="text-default-500 text-sm">{{ config('app.name') }} is operating normally. The health endpoint is reporting <code>200 healthy</code>.</p>
            @endif
        </div>
    </x-admin-v2.card>

    {{-- Action --}}
    @if($isDown)
        <x-admin-v2.card title="Disable Maintenance Mode">
            <p class="text-default-500 text-sm mb-4">Taking the system out of maintenance mode will immediately allow consuming services to resume normal operations.</p>
            <form method="POST" action="{{ route('admin.maintenance.disable') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-success"
                        onclick="return confirm('Take the system out of maintenance mode?')">
                    <i data-lucide="play" class="size-4 me-1"></i> Take System Live
                </button>
            </form>
        </x-admin-v2.card>
    @else
        <x-admin-v2.card title="Enable Maintenance Mode">
            <p class="text-default-500 text-sm mb-4">Placing the system in maintenance mode will signal consuming services to pause. All API health checks will return <code>503 maintenance</code> until the system is taken live again.</p>
            <form method="POST" action="{{ route('admin.maintenance.enable') }}">
                @csrf

                <div class="mb-4">
                    <label for="message" class="form-label">
                        Message <span class="text-default-400 font-normal">(optional)</span>
                    </label>
                    <input type="text"
                           class="form-control @error('message') is-invalid @enderror"
                           id="message" name="message"
                           value="{{ old('message') }}"
                           placeholder="e.g. Scheduled database upgrade — back shortly">
                    @error('message')
                        <p class="text-danger text-xs mt-1">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-default-400 mt-1">Exposed to consuming services via the health endpoint.</p>
                </div>

                <div class="mb-5">
                    <label for="retry_after" class="form-label">
                        Retry After <span class="text-default-400 font-normal">(seconds, optional)</span>
                    </label>
                    <input type="number"
                           class="form-control @error('retry_after') is-invalid @enderror"
                           id="retry_after" name="retry_after"
                           value="{{ old('retry_after') }}"
                           min="60" max="86400"
                           placeholder="e.g. 300">
                    @error('retry_after')
                        <p class="text-danger text-xs mt-1">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-default-400 mt-1">How long consuming services should wait before retrying (60–86,400 seconds).</p>
                </div>

                <button type="submit" class="btn btn-warning"
                        onclick="return confirm('Enable maintenance mode? The system will become unavailable to consuming services.')">
                    <i data-lucide="wrench" class="size-4 me-1"></i> Enable Maintenance Mode
                </button>
            </form>
        </x-admin-v2.card>
    @endif

</div>
@endsection
