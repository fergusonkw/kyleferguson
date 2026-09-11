<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\ThresholdLevel;
use App\Mail\Billing\SmallSupplierThresholdAlert;
use App\Models\Billing\LegalEntity;
use App\Services\Billing\SmallSupplierThreshold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the operator when a legal entity moves closer to the GST/HST
 * small-supplier threshold — once on reaching the warning level, once more on
 * passing the threshold. It stays quiet while nothing has changed, and a level
 * that falls back (an old quarter rolling out of the window) re-arms the alert
 * so a later climb is reported again.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
final class SmallSupplierThresholdAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $legalEntityId) {}

    public function handle(SmallSupplierThreshold $threshold): void
    {
        $entity = LegalEntity::find($this->legalEntityId);

        if ($entity === null) {
            Log::warning('SmallSupplierThresholdAlertJob: legal entity not found', [
                'legal_entity_id' => $this->legalEntityId,
            ]);

            return;
        }

        // Invoices approved while their exchange rate was unavailable get
        // their CAD value now, so the assessment counts them.
        $threshold->valueOutstanding($entity);

        $assessment = $threshold->assess($entity);
        $previous = $entity->threshold_alert_level ?? ThresholdLevel::Clear;

        if ($assessment->level->severity() <= $previous->severity()) {
            if ($assessment->level !== $previous) {
                $entity->forceFill(['threshold_alert_level' => $assessment->level])->save();
            }

            return;
        }

        $recipients = $entity->businesses()->pluck('notification_email')->filter()->unique()->values()->all();

        if ($recipients === []) {
            Log::warning('SmallSupplierThresholdAlertJob: no business to notify', [
                'legal_entity_id' => $entity->id,
                'level' => $assessment->level->value,
            ]);

            return;
        }

        Mail::to($recipients)->send(new SmallSupplierThresholdAlert($assessment));

        $entity->forceFill([
            'threshold_alert_level' => $assessment->level,
            'threshold_alerted_at' => now(),
        ])->save();

        Log::info('SmallSupplierThresholdAlertJob: alert sent', [
            'legal_entity_id' => $entity->id,
            'level' => $assessment->level->value,
            'four_quarter_total' => $assessment->fourQuarterTotal,
            'current_quarter_total' => $assessment->currentQuarterTotal,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SmallSupplierThresholdAlertJob: failed permanently', [
            'legal_entity_id' => $this->legalEntityId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
