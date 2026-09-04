<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\ClientStatus;
use App\Mail\Billing\InvoiceGenerationNeedsAttention;
use App\Mail\Billing\InvoiceReadyForReview;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Builds every draft for a business and period.
 *
 * One client failing must not stop the rest — a mispriced recurring template
 * on one account should not silently cost a month's invoicing everywhere else.
 * Failures are collected and reported, and the operator is told either way:
 * a month where no drafts appear looks exactly like a month with nothing to
 * bill, so silence is the dangerous outcome.
 */
#[Tries(2)]
#[Backoff([300, 900])]
final class GenerateMonthlyDraftsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $period,
    ) {}

    public function handle(InvoiceBuilder $builder): void
    {
        if (! BillingPeriod::isValid($this->period)) {
            Log::error('GenerateMonthlyDraftsJob: invalid period, skipping', [
                'business_id' => $this->businessId,
                'period' => $this->period,
            ]);

            return;
        }

        $business = Business::find($this->businessId);

        if ($business === null) {
            Log::warning('GenerateMonthlyDraftsJob: business not found', ['business_id' => $this->businessId]);

            return;
        }

        $clients = Client::query()
            ->where('business_id', $business->id)
            ->where('status', ClientStatus::Active)
            ->orderBy('name')
            ->get();

        /** @var array<string, string> $failures */
        $failures = [];
        $builtIds = [];

        foreach ($clients as $client) {
            try {
                $invoice = $builder->build($client, $this->period);
                $builtIds[] = $invoice->id;
            } catch (Throwable $e) {
                $failures[$client->name] = $e->getMessage();

                Log::error('GenerateMonthlyDraftsJob: client failed', [
                    'business_id' => $business->id,
                    'client_id' => $client->id,
                    'period' => $this->period,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Only drafts are worth reviewing; a client whose invoice was already
        // issued this period is not news.
        $drafts = Invoice::query()
            ->whereIn('id', $builtIds)
            ->where('status', \App\Enums\Billing\InvoiceStatus::Draft)
            ->with('client')
            ->orderBy('invoice_number')
            ->get();

        if ($drafts->isNotEmpty()) {
            Mail::to($business->notification_email)
                ->send(new InvoiceReadyForReview($business, $this->period, $drafts));
        }

        if ($failures !== []) {
            Mail::to($business->notification_email)
                ->send(new InvoiceGenerationNeedsAttention($business, $this->period, $failures));
        }

        Log::info('GenerateMonthlyDraftsJob: completed', [
            'business_id' => $business->id,
            'period' => $this->period,
            'clients' => $clients->count(),
            'drafts' => $drafts->count(),
            'failures' => count($failures),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateMonthlyDraftsJob: failed permanently', [
            'business_id' => $this->businessId,
            'period' => $this->period,
            'exception' => $exception->getMessage(),
        ]);
    }
}
