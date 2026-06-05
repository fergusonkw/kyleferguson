<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PruneAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit-logs:prune
                            {--days= : Number of days to retain audit logs (defaults to config)}
                            {--dry-run : Run without actually deleting logs}
                            {--force : Skip the confirmation prompt (used by the scheduler)}';

    /**
     * The console command description.
     */
    protected $description = 'Prune old audit logs based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 90));
        $dryRun = $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Pruning audit logs older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})");

        // Get count of logs to be deleted
        $count = AuditLog::where('created_at', '<', $cutoffDate)->count();

        if ($count === 0) {
            $this->info('No audit logs found that need pruning.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} audit logs to prune.");

        if ($dryRun) {
            $this->info('Dry run mode - no logs will be deleted.');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Logs to delete', $count],
                    ['Cutoff date', $cutoffDate->format('Y-m-d H:i:s')],
                    ['Retention days', $days],
                ]
            );

            return self::SUCCESS;
        }

        // Confirm deletion only for an interactive operator. Under the
        // scheduler the command runs non-interactively, so an unanswered
        // confirm() previously defaulted to false and silently cancelled the
        // nightly prune — hence --force / the non-interactive bypass.
        $shouldConfirm = ! $this->option('force') && $this->input->isInteractive();

        if ($shouldConfirm && ! $this->confirm('Do you want to proceed with deletion?', false)) {
            $this->info('Pruning cancelled.');

            return self::FAILURE;
        }

        // Delete in chunks to avoid memory issues
        $deleted = 0;
        $chunkSize = 1000;

        DB::transaction(function () use ($cutoffDate, &$deleted, $chunkSize) {
            while (true) {
                $chunk = AuditLog::where('created_at', '<', $cutoffDate)
                    ->limit($chunkSize)
                    ->pluck('id');

                if ($chunk->isEmpty()) {
                    break;
                }

                $deleted += AuditLog::whereIn('id', $chunk)->delete();

                $this->info("Deleted {$deleted} logs so far...");
            }
        });

        Log::info('Audit logs pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
            'retention_days' => $days,
        ]);

        $this->info("Successfully pruned {$deleted} audit logs.");

        return self::SUCCESS;
    }
}
