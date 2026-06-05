<?php

declare(strict_types=1);

use App\Jobs\Billing\SyncDigitalOceanProjectsJob;
use App\Jobs\QueueHeartbeat;
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
