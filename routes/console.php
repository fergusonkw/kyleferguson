<?php

declare(strict_types=1);

use App\Jobs\Billing\AttributeCostsJob;
use App\Jobs\Billing\DraftReminderDigestJob;
use App\Jobs\Billing\FetchFxRateJob;
use App\Jobs\Billing\GenerateMonthlyDraftsJob;
use App\Jobs\Billing\ReconciliationAlertJob;
use App\Jobs\Billing\SyncProviderBillingJob;
use App\Jobs\QueueHeartbeat;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\ProviderAdapterRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune audit logs daily. --force is required because the scheduler runs
// non-interactively (the confirmation prompt would otherwise default to "no").
Schedule::command('audit-logs:prune --force')->daily()->at('02:00')->withoutOverlapping();

// Queue-worker liveness heartbeat for the Queue Monitor panel.
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->withoutOverlapping();

// Provider resource inventory sync — PAUSED.
//
// DigitalOcean is the only provider with a resource inventory and its billing
// ingestion is parked while Laravel Cloud is evaluated as the hosting platform
// (see docs/billing-policy.md § DigitalOcean — parked). The adapter and its
// tests remain; re-enable this block when a resource-sync provider is active.
//
// Schedule::call(function (): void {
//     $registry = app(ProviderAdapterRegistry::class);
//
//     CostProvider::query()
//         ->where('enabled', true)
//         ->each(function (CostProvider $provider) use ($registry): void {
//             if ($registry->supportsResourceSync($provider->slug)) {
//                 SyncProviderResourcesJob::dispatch($provider->id);
//             }
//         });
// })->daily()->at('02:00')->name('billing:sync-provider-resources')->withoutOverlapping();

// Daily cost ingestion for every provider with a billing adapter, across the
// periods still open for revision.
Schedule::call(function (): void {
    $registry = app(ProviderAdapterRegistry::class);
    $periods = BillingPeriod::openPeriods();

    CostProvider::query()
        ->where('enabled', true)
        ->each(function (CostProvider $provider) use ($registry, $periods): void {
            if (! $registry->supportsBillingSync($provider->slug)) {
                return;
            }

            foreach ($periods as $period) {
                SyncProviderBillingJob::dispatch($provider->id, $period);
            }
        });
})->daily()->at('03:00')->name('billing:sync-provider-billing')->withoutOverlapping();

// Attribute the freshly ingested costs to projects.
Schedule::call(function (): void {
    $periods = BillingPeriod::openPeriods();

    Business::query()->each(function (Business $business) use ($periods): void {
        foreach ($periods as $period) {
            AttributeCostsJob::dispatch($business->id, $period);
        }
    });
})->daily()->at('03:45')->name('billing:attribute-costs')->withoutOverlapping();

// Warm the FX cache for the closed month, one job per currency clients
// actually bill in, so invoice generation is not the first thing to discover a
// rate is unavailable. USD→USD needs no lookup and is skipped.
Schedule::call(function (): void {
    $period = BillingPeriod::previous();

    Client::query()
        ->distinct()
        ->pluck('billing_currency')
        ->reject(fn (string $currency): bool => mb_strtoupper($currency) === 'USD')
        ->each(fn (string $currency) => FetchFxRateJob::dispatch('USD', mb_strtoupper($currency), $period));
})->monthlyOn(1, '01:00')->name('billing:fetch-fx-rates')->withoutOverlapping();

// Build the closed month's drafts once cost ingestion, attribution and FX
// have all had their run. Nothing reaches a client without approval.
Schedule::call(function (): void {
    $period = BillingPeriod::previous();

    Business::query()->each(function (Business $business) use ($period): void {
        GenerateMonthlyDraftsJob::dispatch($business->id, $period);
    });
})->monthlyOn(1, '06:00')->name('billing:generate-monthly-drafts')->withoutOverlapping();

// Chase drafts that have sat unapproved for more than a day. Each business is
// nudged at the hour it chose.
Schedule::call(function (): void {
    $hourNow = now()->format('H');

    Business::query()->each(function (Business $business) use ($hourNow): void {
        // Stored as a `time` string ("08:00:00"); only the hour is compared,
        // since this check itself only runs on the hour.
        if (mb_substr((string) $business->daily_reminder_time, 0, 2) === $hourNow) {
            DraftReminderDigestJob::dispatch($business->id);
        }
    });
})->hourlyAt(0)->name('billing:draft-reminders')->withoutOverlapping();

// Alert the operator about anything that could not be accounted for. Silent
// when a period reconciles cleanly.
Schedule::call(function (): void {
    $period = BillingPeriod::current();

    Business::query()->each(function (Business $business) use ($period): void {
        ReconciliationAlertJob::dispatch($business->id, $period);
    });
})->daily()->at('08:00')->name('billing:reconciliation-alert')->withoutOverlapping();
