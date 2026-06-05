<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Billing\BillingDashboardController;
use App\Http\Controllers\Admin\Billing\BusinessController;
use App\Http\Controllers\Admin\Billing\BusinessSwitcherController;
use App\Http\Controllers\Admin\Billing\ClientController as BillingClientController;
use App\Http\Controllers\Admin\Billing\CostProviderController;
use App\Http\Controllers\Admin\Billing\ProjectController as BillingProjectController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogViewerController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\QueueMonitorController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserSettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

// The marketing site lives as static files in public/index.html. Apache
// serves it directly via DirectoryIndex, so Laravel does not own `/`.

// Public contact form (ports the legacy contact.php endpoint).
Route::post('/contact', [App\Http\Controllers\ContactController::class, 'submit'])
    ->middleware('throttle:contact')
    ->name('contact.submit');

// Guest authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/password/reset', [AuthController::class, 'showResetRequest'])->name('password.request');
Route::post('/password/email', [AuthController::class, 'sendResetLink'])->middleware('throttle:login')->name('password.email');
Route::get('/password/reset/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:login')->name('password.update');

// Two-factor login challenge (reached mid-login, before the session is fully
// authenticated — must NOT sit behind the auth middleware).
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1')->name('two-factor.login.store');

// Email verification — authenticated but intentionally OUTSIDE the verified /
// 2FA gates (a user must still be able to verify). Route names are unprefixed
// so Laravel's signed VerifyEmail URL resolves without overrides.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');
});

// Admin panel (protected)
Route::prefix('admin')->name('admin.')->middleware(['auth', 'email.verified', '2fa.enrolled'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    // User settings
    Route::get('settings', [UserSettingsController::class, 'index'])->name('settings.index');
    Route::patch('settings/email', [UserSettingsController::class, 'updateEmail'])->name('settings.update-email');
    Route::patch('settings/password', [UserSettingsController::class, 'updatePassword'])->name('settings.update-password');
    Route::patch('settings/theme', [UserSettingsController::class, 'updateTheme'])->name('settings.update-theme');

    // Two-factor enrollment (managed from the settings page)
    Route::post('settings/two-factor', [TwoFactorController::class, 'enable'])->name('settings.two-factor.enable');
    Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('settings.two-factor.confirm');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'disable'])->name('settings.two-factor.disable');
    Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('settings.two-factor.recovery-codes');

    // User management
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/data', [UserController::class, 'data'])->name('users.data');
    Route::get('users/roles', [UserController::class, 'roles'])->name('users.roles');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::patch('users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
    Route::patch('users/{user}/lock', [UserController::class, 'lock'])->name('users.lock');

    // Role & permission management
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/data', [RoleController::class, 'data'])->name('roles.data');
    Route::get('roles/permissions', [RoleController::class, 'permissions'])->name('roles.permissions');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // Audit logs
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');

    // Queue monitor
    Route::get('queue-monitor', [QueueMonitorController::class, 'index'])->name('queue-monitor.index');
    Route::get('queue-monitor/jobs', [QueueMonitorController::class, 'jobs'])->name('queue-monitor.jobs');
    Route::get('queue-monitor/failed-jobs', [QueueMonitorController::class, 'failedJobs'])->name('queue-monitor.failed-jobs');
    Route::get('queue-monitor/queues', [QueueMonitorController::class, 'queues'])->name('queue-monitor.queues');
    Route::post('queue-monitor/failed-jobs/{uuid}/retry', [QueueMonitorController::class, 'retryFailedJob'])->name('queue-monitor.retry');
    Route::delete('queue-monitor/failed-jobs/{uuid}', [QueueMonitorController::class, 'deleteFailedJob'])->name('queue-monitor.delete');
    Route::post('queue-monitor/failed-jobs/retry-all', [QueueMonitorController::class, 'retryAllFailedJobs'])->name('queue-monitor.retry-all');
    Route::delete('queue-monitor/failed-jobs', [QueueMonitorController::class, 'flushFailedJobs'])->name('queue-monitor.flush');

    // Log viewer
    Route::get('log-viewer', [LogViewerController::class, 'index'])->name('log-viewer.index');
    Route::get('log-viewer/show', [LogViewerController::class, 'show'])->name('log-viewer.show');

    // Maintenance mode
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceController::class, 'enable'])->name('maintenance.enable');
    Route::delete('maintenance', [MaintenanceController::class, 'disable'])->name('maintenance.disable');

    // Billing
    Route::prefix('billing')->name('billing.')->middleware('billing.current-business')->group(function (): void {
        Route::get('/', [BillingDashboardController::class, 'index'])->name('index');
        Route::post('switch/{business}', [BusinessSwitcherController::class, 'switch'])->name('switch');

        // Businesses
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/data', [BusinessController::class, 'data'])->name('businesses.data');
        Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');
        Route::get('businesses/{business}/edit', [BusinessController::class, 'edit'])->name('businesses.edit');
        Route::put('businesses/{business}', [BusinessController::class, 'update'])->name('businesses.update');
        Route::delete('businesses/{business}', [BusinessController::class, 'destroy'])->name('businesses.destroy');

        // Clients
        Route::get('clients', [BillingClientController::class, 'index'])->name('clients.index');
        Route::get('clients/data', [BillingClientController::class, 'data'])->name('clients.data');
        Route::post('clients', [BillingClientController::class, 'store'])->name('clients.store');
        Route::get('clients/{client}/edit', [BillingClientController::class, 'edit'])->name('clients.edit');
        Route::put('clients/{client}', [BillingClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client}', [BillingClientController::class, 'destroy'])->name('clients.destroy');

        // Projects
        Route::get('projects', [BillingProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/data', [BillingProjectController::class, 'data'])->name('projects.data');
        Route::get('projects/available-clients', [BillingProjectController::class, 'availableClients'])->name('projects.available-clients');
        Route::get('projects/available-do-projects', [BillingProjectController::class, 'availableDoProjects'])->name('projects.available-do-projects');
        Route::get('projects/unattributed', [BillingProjectController::class, 'unattributedResources'])->name('projects.unattributed');
        Route::post('projects', [BillingProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}/edit', [BillingProjectController::class, 'edit'])->name('projects.edit');
        Route::put('projects/{project}', [BillingProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [BillingProjectController::class, 'destroy'])->name('projects.destroy');

        // Cost providers
        Route::get('cost-providers', [CostProviderController::class, 'index'])->name('cost-providers.index');
        Route::get('cost-providers/data', [CostProviderController::class, 'data'])->name('cost-providers.data');
        Route::post('cost-providers', [CostProviderController::class, 'store'])->name('cost-providers.store');
        Route::get('cost-providers/{costProvider}/edit', [CostProviderController::class, 'edit'])->name('cost-providers.edit');
        Route::put('cost-providers/{costProvider}', [CostProviderController::class, 'update'])->name('cost-providers.update');
        Route::delete('cost-providers/{costProvider}', [CostProviderController::class, 'destroy'])->name('cost-providers.destroy');
        Route::post('cost-providers/{costProvider}/sync', [CostProviderController::class, 'sync'])->name('cost-providers.sync');
    });
});
