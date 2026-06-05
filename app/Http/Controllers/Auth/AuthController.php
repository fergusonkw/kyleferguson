<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Show the login form
     */
    public function showLogin(Request $request): View
    {
        // Store the intended URL if provided via 'redirect' parameter
        if ($request->has('redirect')) {
            $intendedUrl = $request->input('redirect');

            // Validate that the redirect URL is internal (security measure)
            if (str_starts_with($intendedUrl, '/') && ! str_starts_with($intendedUrl, '//')) {
                session()->put('url.intended', url($intendedUrl));
            }
        }

        return view('admin-v2.auth1.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            $this->auditLogger->logAuth('auth.login_failed', null, [
                'email' => $credentials['email'],
                'guard' => 'web',
            ]);

            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isActive()) {
            $reason = $user->is_locked ? 'locked' : 'disabled';

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->auditLogger->logAuth('auth.login_blocked', $user, [
                'reason' => $reason,
                'guard' => 'web',
            ]);

            return back()->withErrors([
                'email' => $user->is_locked
                    ? 'This account has been locked. Please contact an administrator.'
                    : 'This account has been disabled. Please contact an administrator.',
            ])->onlyInput('email');
        }

        if ($user->hasTwoFactorEnabled()) {
            // Don't keep a half-authenticated session around: drop the login
            // and resume it only after the 2FA challenge passes.
            Auth::logout();

            $request->session()->put(TwoFactorChallengeController::SESSION_USER, $user->id);
            $request->session()->put(TwoFactorChallengeController::SESSION_REMEMBER, $remember);

            $this->auditLogger->logAuth('2fa_challenged', $user, ['guard' => 'web']);

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        $this->auditLogger->logAuth('auth.login', $user, ['guard' => 'web']);

        return redirect()->intended(route('admin.home'));
    }

    /**
     * Handle logout request
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->auditLogger->logAuth('auth.logout', $user, ['guard' => 'web']);
        }

        return redirect()->route('login');
    }

    /**
     * Show the password reset request form
     */
    public function showResetRequest(): View
    {
        return view('admin-v2.auth1.reset-pass');
    }

    /**
     * Handle password reset link request.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        $user = User::where('email', $request->input('email'))->first();
        $this->auditLogger->logAuth('auth.password_reset_requested', $user, [
            'email' => $request->input('email'),
            'status' => $status,
        ]);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Show the password reset form.
     */
    public function showResetForm(Request $request, string $token): View
    {
        return view('admin-v2.auth1.new-pass', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Handle the password reset.
     */
    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $resetUser = null;
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) use (&$resetUser) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all existing API tokens; the user has just rotated their password.
                $user->tokens()->delete();

                $resetUser = $user;
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET && $resetUser instanceof User) {
            $this->auditLogger->logAuth('auth.password_reset_completed', $resetUser);
        }

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }
}
