<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EmailVerificationController extends Controller
{
    /**
     * The "please verify your email" interstitial.
     */
    public function notice(Request $request): RedirectResponse|View
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('admin.home'));
        }

        return view('admin-v2.auth1.verify-email');
    }

    /**
     * Handle the signed verification link (validates id/hash, marks verified,
     * fires the Verified event).
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('admin.home'));
        }

        $request->fulfill();

        return redirect()->intended(route('admin.home'))
            ->with('success', 'Your email address has been verified.');
    }

    /**
     * Resend the verification email (route is rate-limited).
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('admin.home'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A fresh verification link has been sent to your email address.');
    }
}
