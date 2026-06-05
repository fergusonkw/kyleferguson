<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Facades\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmTwoFactorRequest;
use App\Http\Requests\Admin\DisableTwoFactorRequest;
use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    /**
     * Begin enrollment: generate (but do not yet confirm) a secret and a set
     * of recovery codes. 2FA is not active until confirm() succeeds.
     */
    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.settings.index')
                ->with('error', 'Two-factor authentication is already enabled.');
        }

        $user->forceFill([
            'two_factor_secret' => $this->authenticator->generateSecretKey(),
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('admin.settings.index')
            ->with('success', 'Scan the QR code, then enter a code to finish enabling two-factor authentication.');
    }

    /**
     * Confirm enrollment by verifying a code against the pending secret.
     */
    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.settings.index')
                ->with('error', 'Start two-factor setup before confirming a code.');
        }

        if (! $this->authenticator->verify($user->two_factor_secret, $request->validated('code'))) {
            return back()->withErrors([
                'code' => 'That code is incorrect or expired. Try again.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        AuditLogger::logSecurity('2fa_enabled', $user, ['guard' => 'web']);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Two-factor authentication is now enabled. Store your recovery codes somewhere safe.');
    }

    /**
     * Turn off 2FA. Re-authentication (current password) is required.
     */
    public function disable(DisableTwoFactorRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::logSecurity('2fa_disabled', $user, ['guard' => 'web']);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Two-factor authentication has been disabled.');
    }

    /**
     * Regenerate recovery codes (invalidates the old set).
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.settings.index')
                ->with('error', 'Enable two-factor authentication first.');
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
        ])->save();

        AuditLogger::logSecurity('2fa_recovery_codes_regenerated', $user, ['guard' => 'web']);

        return redirect()->route('admin.settings.index')
            ->with('success', 'New recovery codes generated. Your previous codes no longer work.');
    }
}
