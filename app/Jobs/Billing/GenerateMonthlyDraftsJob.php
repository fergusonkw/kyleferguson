<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Mail\Billing\InvoiceGenerationNeedsAttention;
use App\Mail\Billing\InvoiceReadyForReview;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class GenerateMonthlyDraftsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $businessId,
        public readonly string $period,
    ) {}

    public function handle(InvoiceBuilder $builder): void
    {
        $business = Business::findOrFail($this->businessId);

        $clients = $business->clients()
            ->where('status', 'active')
            ->get();

        foreach ($clients as $client) {
            $this->generateForClient($builder, $business, $client);
        }
    }

    private function generateForClient(InvoiceBuilder $builder, Business $business, Client $client): void
    {
        try {
            $invoice = $builder->build($business, $client, $this->period);

            Mail::to($business->notification_email)
                ->queue(new InvoiceReadyForReview($invoice));
        } catch (Throwable $e) {
            Mail::to($business->notification_email)
                ->queue(new InvoiceGenerationNeedsAttention(
                    $business,
                    $this->period,
                    $e->getMessage(),
                ));
        }
    }
}
