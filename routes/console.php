<?php

declare(strict_types=1);

use App\Jobs\Billing\FetchFxRateJob;
use App\Jobs\Billing\ReconciliationAlertJob;
use App\Jobs\Billing\SyncDigitalOceanBillingJob;
use App\Jobs\Billing\SyncDigitalOceanProjectsJob;
use App\Jobs\QueueHeartbeat;
use App\Models\Billing\Business;
use App\Models\Billing\CostProvider;
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

// Daily DigitalOcean project + resource sync per enabled cost provider.
Schedule::call(function (): void {
    CostProvider::query()
        ->where('enabled', true)
        ->where('slug', 'digitalocean')
        ->each(function (CostProvider $provider): void {
            SyncDigitalOceanProjectsJob::dispatch($provider->id);
        });
})->daily()->at('02:00')->name('billing:sync-do-projects')->withoutOverlapping();

// Daily DigitalOcean billing sync per enabled cost provider (current month).
Schedule::call(function (): void {
    $period = now()->format('Y-m');

    CostProvider::query()
        ->where('enabled', true)
        ->where('slug', 'digitalocean')
        ->each(function (CostProvider $provider) use ($period): void {
            SyncDigitalOceanBillingJob::dispatch($provider->id, $period);
        });
})->daily()->at('03:00')->name('billing:sync-do-billing')->withoutOverlapping();

// Monthly FX rate fetch for all currency pairs used across businesses.
// Runs on the 1st of each month so rates are available before draft generation.
Schedule::call(function (): void {
    $period = now()->format('Y-m');

    Business::query()
        ->whereNotNull('supported_currencies')
        ->each(function (Business $business) use ($period): void {
            $currencies = $business->supported_currencies ?? [];

            foreach ($currencies as $currency) {
                if ($currency !== 'USD') {
                    FetchFxRateJob::dispatch('USD', $currency, $period);
                }
            }
        });
})->monthlyOn(1, '01:00')->name('billing:fetch-fx-rates')->withoutOverlapping();

// Daily reconciliation alert — flags unattributed resources and cost gaps.
Schedule::job(new ReconciliationAlertJob)
    ->daily()
    ->at('08:00')
    ->name('billing:reconciliation-alert')
    ->withoutOverlapping();
