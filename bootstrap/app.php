<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Keep the maintenance toggle reachable so an admin can lift maintenance
        // mode from within the panel while the app is down.
        $middleware->preventRequestsDuringMaintenance(except: [
            'maintenance',
        ]);

        $middleware->alias([
            'email.verified' => App\Http\Middleware\EnsureEmailVerified::class,
            '2fa.enrolled' => App\Http\Middleware\EnsureTwoFactorEnrolled::class,
            'billing.current-business' => App\Http\Middleware\ShareCurrentBusiness::class,
        ]);

        // Public marketing form posts JSON without a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'contact',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry (no-op when SENTRY_LARAVEL_DSN is unset).
        Integration::handles($exceptions);
    })->create();
