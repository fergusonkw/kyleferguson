<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Mail\Billing\ReconciliationAlert;
use App\Models\Billing\Business;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\ReconciliationReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the operator when a period's costs cannot be fully accounted for —
 * an unattributed resource, an unattributed cost, or a non-zero gap against a
 * provider that reports its own total. Silent when everything reconciles.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
final class ReconciliationAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $period,
    ) {}

    public function handle(ReconciliationReporter $reporter): void
    {
        if (! BillingPeriod::isValid($this->period)) {
            Log::error('ReconciliationAlertJob: invalid period, skipping', [
                'business_id' => $this->businessId,
                'period' => $this->period,
            ]);

            return;
        }

        $business = Business::find($this->businessId);

        if ($business === null) {
            Log::warning('ReconciliationAlertJob: business not found', [
                'business_id' => $this->businessId,
            ]);

            return;
        }

        $summary = $reporter->summarize($business->id, $this->period);

        if (! $summary->needsAttention()) {
            Log::info('ReconciliationAlertJob: period reconciles, no alert sent', [
                'business_id' => $business->id,
                'period' => $this->period,
            ]);

            return;
        }

        Mail::to($business->notification_email)->send(new ReconciliationAlert(
            business: $business,
            summary: $summary,
            unattributedResources: $reporter->unattributedResources($business->id),
        ));

        Log::info('ReconciliationAlertJob: alert sent', [
            'business_id' => $business->id,
            'period' => $this->period,
            'unattributed_resources' => $summary->unattributedResourceCount,
            'unattributed_cost' => $summary->unattributedCost,
            'cost_gap' => $summary->costGap,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ReconciliationAlertJob: failed permanently', [
            'business_id' => $this->businessId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
