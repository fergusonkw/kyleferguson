<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\Business;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Logs a reconciliation alert when there are unattributed resources or a
 * non-zero cost gap for any active business in the current period.
 * Phase 3 will replace this with proper operator email notifications.
 */
final class ReconciliationAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $period = null) {}

    public function handle(ReconciliationReporter $reporter): void
    {
        $period = $this->period ?? now()->format('Y-m');

        Business::query()->each(function (Business $business) use ($period, $reporter): void {
            $unattributed = $reporter->unattributedResourceCount($business);
            $gap = $reporter->costGap($business, $period);

            if ($unattributed > 0 || $gap['gap_usd'] > 0.001) {
                Log::warning('ReconciliationAlertJob: reconciliation issues detected', [
                    'business_id' => $business->id,
                    'business_name' => $business->name,
                    'period' => $period,
                    'unattributed_resources' => $unattributed,
                    'do_total_usd' => $gap['do_total_usd'],
                    'attributed_usd' => $gap['attributed_usd'],
                    'gap_usd' => $gap['gap_usd'],
                ]);
            } else {
                Log::info('ReconciliationAlertJob: no issues', [
                    'business_id' => $business->id,
                    'period' => $period,
                ]);
            }
        });
    }
}
