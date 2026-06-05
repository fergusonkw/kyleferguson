<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Billing\Business;
use App\Models\Billing\Client as BillingClient;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Role;
use App\Models\User;
use App\Policies\Billing\BusinessPolicy;
use App\Policies\Billing\ClientPolicy as BillingClientPolicy;
use App\Policies\Billing\CostProviderPolicy;
use App\Policies\Billing\ProjectPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\AuditLogger;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(CurrentBusiness::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(BillingClient::class, BillingClientPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(CostProvider::class, CostProviderPolicy::class);

        // Permission-backed gates for resources without an Eloquent model.
        Gate::define('viewAny-audit-logs', fn (User $user) => $user->hasPermission(Permission::ViewAuditLogs->value));
        Gate::define('viewAny-log-viewer', fn (User $user) => $user->hasPermission(Permission::ViewLogViewer->value));
        Gate::define('viewAny-queue-monitor', fn (User $user) => $user->hasPermission(Permission::ViewQueueMonitor->value));
        Gate::define('viewAny-maintenance', fn (User $user) => $user->hasPermission(Permission::ManageMaintenance->value));
        Gate::define('viewAny-billing', fn (User $user) => $user->hasPermission(Permission::ViewBilling->value));

        // Super admins bypass all authorization checks.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
    }

    protected function configureRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by((string) $request->user()->id)
            : Limit::perMinute(60)->by($request->ip()));

        // Login throttle keyed by email+IP and by IP, to slow credential stuffing.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Public contact form — matches the legacy contact.php limit (5/hour/IP).
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
    }
}
