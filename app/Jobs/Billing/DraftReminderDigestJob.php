<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Mail\Billing\DraftReminderDigest;
use App\Models\Billing\Business;
use App\Models\Billing\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Daily nudge about drafts that have sat unapproved for more than a day.
 *
 * An unapproved draft is work done and not billed for, so this repeats until
 * the draft is approved or voided rather than firing once and being missed.
 * The one-day grace stops it from chasing drafts generated this morning.
 */
#[Tries(3)]
#[Backoff([60, 300, 900])]
final class DraftReminderDigestJob implements ShouldQueue
{
    use Queueable;

    private const GRACE_HOURS = 24;

    public function __construct(public readonly int $businessId) {}

    public function handle(): void
    {
        $business = Business::find($this->businessId);

        if ($business === null) {
            Log::warning('DraftReminderDigestJob: business not found', ['business_id' => $this->businessId]);

            return;
        }

        $drafts = Invoice::query()
            ->forBusiness($business->id)
            ->where('status', InvoiceStatus::Draft)
            ->where('created_at', '<=', now()->subHours(self::GRACE_HOURS))
            ->with('client')
            ->orderBy('created_at')
            ->get();

        if ($drafts->isEmpty()) {
            Log::info('DraftReminderDigestJob: nothing awaiting approval', ['business_id' => $business->id]);

            return;
        }

        Mail::to($business->notification_email)->send(new DraftReminderDigest($business, $drafts));

        Log::info('DraftReminderDigestJob: reminder sent', [
            'business_id' => $business->id,
            'drafts' => $drafts->count(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('DraftReminderDigestJob: failed permanently', [
            'business_id' => $this->businessId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
