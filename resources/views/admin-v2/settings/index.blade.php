@extends('admin-v2.layouts.vertical', ['title' => 'User Settings'])

@section('content')
<x-admin-v2.page-title
    title="User Settings"
    :breadcrumbs="[['label' => 'User Settings', 'active' => true]]"
/>

@if(session('success'))
    <x-admin-v2.alert type="success" class="mb-5">{{ session('success') }}</x-admin-v2.alert>
@endif
@if(session('error'))
    <x-admin-v2.alert type="danger" class="mb-5">{{ session('error') }}</x-admin-v2.alert>
@endif
@if(session('status'))
    <x-admin-v2.alert type="info" class="mb-5">{{ session('status') }}</x-admin-v2.alert>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

    <x-admin-v2.card title="Email Address">
        <form action="{{ route('admin.settings.update-email') }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="mb-4">
                <label for="email" class="form-label">Email Address</label>
                <input type="email"
                       class="form-control @error('email') is-invalid @enderror"
                       id="email" name="email"
                       value="{{ old('email', auth()->user()->email) }}" required>
                @error('email')
                    <p class="text-danger text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="size-4 me-1"></i> Update Email
            </button>
        </form>
    </x-admin-v2.card>

    <x-admin-v2.card title="Theme Preference">
        <form action="{{ route('admin.settings.update-theme') }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="mb-4">
                <label for="theme_preference" class="form-label">Preferred Theme</label>
                <select class="form-select @error('theme_preference') is-invalid @enderror"
                        id="theme_preference" name="theme_preference" required>
                    <option value="light" {{ old('theme_preference', auth()->user()->theme_preference) === 'light' ? 'selected' : '' }}>Light</option>
                    <option value="dark" {{ old('theme_preference', auth()->user()->theme_preference) === 'dark' ? 'selected' : '' }}>Dark</option>
                </select>
                @error('theme_preference')
                    <p class="text-danger text-xs mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-default-400 mt-1">Your preferred theme will be applied when you log in.</p>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="size-4 me-1"></i> Update Theme
            </button>
        </form>
    </x-admin-v2.card>

    <x-admin-v2.card title="Change Password">
        <form action="{{ route('admin.settings.update-password') }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="mb-4">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password"
                       class="form-control @error('current_password') is-invalid @enderror"
                       id="current_password" name="current_password" required>
                @error('current_password')
                    <p class="text-danger text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">New Password</label>
                <input type="password"
                       class="form-control @error('password') is-invalid @enderror"
                       id="password" name="password" required>
                @error('password')
                    <p class="text-danger text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="password_confirmation" class="form-label">Confirm New Password</label>
                <input type="password"
                       class="form-control"
                       id="password_confirmation" name="password_confirmation" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="size-4 me-1"></i> Update Password
            </button>
        </form>
    </x-admin-v2.card>

    <div class="lg:col-span-2">
        <x-admin-v2.card title="Two-Factor Authentication">
            @if($twoFactorState === 'disabled')
                <p class="text-default-500 mb-4">
                    Add an extra layer of security. After enabling, you'll enter a code
                    from your authenticator app each time you sign in.
                </p>
                <form action="{{ route('admin.settings.two-factor.enable') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="shield-check" class="size-4 me-1"></i> Enable Two-Factor Authentication
                    </button>
                </form>

            @elseif($twoFactorState === 'pending')
                <p class="text-default-500 mb-4">
                    Scan this QR code with your authenticator app (Google Authenticator,
                    Authy, 1Password…), then enter the 6-digit code to finish.
                </p>
                <div class="flex flex-col md:flex-row gap-6 mb-5">
                    <div class="shrink-0">{!! $twoFactorQrSvg !!}</div>
                    <div>
                        <p class="text-sm font-medium mb-1">Can't scan? Enter this key manually:</p>
                        <code class="block bg-light px-3 py-2 rounded text-sm break-all mb-4">{{ $twoFactorSecret }}</code>
                        <p class="text-sm font-medium mb-1">Recovery codes (store these safely):</p>
                        <ul class="grid grid-cols-2 gap-1 text-sm font-mono">
                            @foreach($twoFactorRecoveryCodes as $recoveryCode)
                                <li>{{ $recoveryCode }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <form action="{{ route('admin.settings.two-factor.confirm') }}" method="POST" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="code" class="form-label">Authentication Code</label>
                        <input type="text" inputmode="numeric" autocomplete="one-time-code"
                               class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code" required autofocus>
                        @error('code')
                            <p class="text-danger text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="shield-check" class="size-4 me-1"></i> Confirm &amp; Enable
                    </button>
                </form>
                <form action="{{ route('admin.settings.two-factor.disable') }}" method="POST" class="mt-3"
                      onsubmit="return confirm('Discard this two-factor setup?');">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="current_password" value="">
                    <button type="submit" class="btn btn-light btn-sm text-default-500">Cancel setup</button>
                </form>

            @else
                <div class="flex items-center gap-2 mb-4">
                    <span class="badge bg-success">Enabled</span>
                    <span class="text-default-500 text-sm">Two-factor authentication is protecting your account.</span>
                </div>

                <p class="text-sm font-medium mb-1">Recovery codes</p>
                <p class="text-xs text-default-400 mb-2">
                    Each code can be used once if you lose access to your authenticator app.
                </p>
                <ul class="grid grid-cols-2 md:grid-cols-4 gap-1 text-sm font-mono mb-3">
                    @forelse($twoFactorRecoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @empty
                        <li class="text-default-400">No recovery codes remain — regenerate below.</li>
                    @endforelse
                </ul>
                <div class="flex flex-wrap gap-2">
                    <form action="{{ route('admin.settings.two-factor.recovery-codes') }}" method="POST"
                          onsubmit="return confirm('Generate new recovery codes? Your existing codes will stop working.');">
                        @csrf
                        <button type="submit" class="btn btn-light btn-sm">
                            <i data-lucide="refresh-cw" class="size-4 me-1"></i> Regenerate Recovery Codes
                        </button>
                    </form>
                </div>

                <hr class="my-5 border-default-200">

                <form action="{{ route('admin.settings.two-factor.disable') }}" method="POST"
                      onsubmit="return confirm('Disable two-factor authentication?');">
                    @csrf
                    @method('DELETE')
                    <div class="mb-3 max-w-sm">
                        <label for="current_password" class="form-label">Confirm your password to disable</label>
                        <input type="password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               id="current_password" name="current_password" required>
                        @error('current_password')
                            <p class="text-danger text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i data-lucide="shield-check" class="size-4 me-1"></i> Disable Two-Factor Authentication
                    </button>
                </form>
            @endif
        </x-admin-v2.card>
    </div>

</div>
@endsection
