<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Facades\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class TwoFactorChallengeController extends Controller
{
    public const SESSION_USER = 'login.2fa.user_id';

    public const SESSION_REMEMBER = 'login.2fa.remember';

    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    /**
     * Show the challenge screen (only reachable mid-login).
     */
    public function create(Request $request): RedirectResponse|View
    {
        if (! $request->session()->has(self::SESSION_USER)) {
            return redirect()->route('login');
        }

        return view('admin-v2.auth1.two-factor-challenge');
    }

    /**
     * Verify a TOTP code or a recovery code, then complete the login.
     */
    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get(self::SESSION_USER);

        if ($userId === null) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget([self::SESSION_USER, self::SESSION_REMEMBER]);

            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $code = trim((string) $request->input('code'));
        $recoveryCode = trim((string) $request->input('recovery_code'));

        if ($recoveryCode !== '') {
            if (! $user->useTwoFactorRecoveryCode($recoveryCode)) {
                return $this->failed($user, 'recovery');
            }

            AuditLogger::logAuth('recovery_code_used', $user, ['guard' => 'web']);
        } elseif ($code !== '' && $this->authenticator->verify((string) $user->two_factor_secret, $code)) {
            AuditLogger::logAuth('2fa_passed', $user, ['guard' => 'web']);
        } else {
            return $this->failed($user, 'totp');
        }

        $remember = (bool) $request->session()->pull(self::SESSION_REMEMBER, false);
        $request->session()->forget(self::SESSION_USER);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.home'));
    }

    private function failed(User $user, string $method): RedirectResponse
    {
        AuditLogger::logAuth('2fa_failed', $user, ['guard' => 'web', 'method' => $method]);

        return back()->withErrors([
            'code' => 'The provided two-factor code was invalid.',
        ]);
    }
}
