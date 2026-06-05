<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Mail\Billing\DraftReminderDigest;
use App\Models\Billing\Business;
use App\Models\Billing\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

final class DraftReminderDigestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $businessId) {}

    public function handle(): void
    {
        $business = Business::findOrFail($this->businessId);

        $drafts = Invoice::query()
            ->where('business_id', $business->id)
            ->where('status', InvoiceStatus::Draft)
            ->where('created_at', '<=', now()->subDay())
            ->with('client')
            ->get();

        if ($drafts->isEmpty()) {
            return;
        }

        Mail::to($business->notification_email)
            ->queue(new DraftReminderDigest($business, $drafts));
    }
}
