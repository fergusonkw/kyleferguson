<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin panel behind a verified email address. The verification
 * notice/resend/verify routes live in their own ungated group, so there is
 * no redirect loop; settings.* is allowlisted so a user who typed the wrong
 * address can still correct it.
 */
final class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && str_starts_with($routeName, 'admin.settings.')) {
            return $next($request);
        }

        return redirect()->route('verification.notice');
    }
}
