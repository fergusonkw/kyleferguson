<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the application mandates two-factor auth (config auth.two_factor.required),
 * force enrollment before the panel is usable. settings.* is allowlisted so the
 * user can actually set it up.
 */
final class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresTwoFactor() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && str_starts_with($routeName, 'admin.settings.')) {
            return $next($request);
        }

        return redirect()->route('admin.settings.index')->with(
            'error',
            'Two-factor authentication is required. Please set it up to continue.'
        );
    }
}
