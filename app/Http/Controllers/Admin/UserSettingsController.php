<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserEmailRequest;
use App\Http\Requests\Admin\UpdateUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserThemeRequest;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class UserSettingsController extends Controller
{
    public function index(TwoFactorAuthenticator $authenticator): View
    {
        $user = auth()->user();

        $twoFactorState = match (true) {
            $user->hasTwoFactorEnabled() => 'enabled',
            $user->two_factor_secret !== null => 'pending',
            default => 'disabled',
        };

        $twoFactorQrSvg = null;
        if ($twoFactorState === 'pending') {
            $twoFactorQrSvg = $authenticator->qrCodeSvg($user->email, $user->two_factor_secret);
        }

        return view('admin-v2.settings.index', [
            'twoFactorState' => $twoFactorState,
            'twoFactorQrSvg' => $twoFactorQrSvg,
            'twoFactorSecret' => $twoFactorState === 'pending' ? $user->two_factor_secret : null,
            'twoFactorRecoveryCodes' => $twoFactorState === 'disabled' ? [] : $user->twoFactorRecoveryCodes(),
        ]);
    }

    public function updateEmail(UpdateUserEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $newEmail = $request->validated('email');

        if ($newEmail === $user->email) {
            return redirect()->route('admin.settings.index')
                ->with('success', 'Email address updated successfully.');
        }

        // Changing the address invalidates verification — re-verify the new one.
        $user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        return redirect()->route('admin.settings.index')
            ->with('success', 'Email address updated. Check your inbox to verify the new address.');
    }

    public function updatePassword(UpdateUserPasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Password updated successfully.');
    }

    public function updateTheme(UpdateUserThemeRequest $request): RedirectResponse
    {
        $request->user()->update(['theme_preference' => $request->validated('theme_preference')]);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Theme preference updated successfully.');
    }
}
