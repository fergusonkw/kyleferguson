<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\Dto\ReconciliationSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Operator-facing alert: costs for a period cannot be fully accounted for.
 */
final class ReconciliationAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, ProviderResource>  $unattributedResources
     */
    public function __construct(
        public Business $business,
        public ReconciliationSummary $summary,
        public Collection $unattributedResources,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] Billing reconciliation needs attention — %s',
                $this->business->name,
                BillingPeriod::label($this->summary->period),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.reconciliation-alert',
            text: 'emails.billing.reconciliation-alert-text',
            with: [
                'businessName' => $this->business->name,
                'periodLabel' => BillingPeriod::label($this->summary->period),
                'summary' => $this->summary,
                'resources' => $this->unattributedResources,
                'reconciliationUrl' => route('admin.billing.reconciliation.index', [
                    'period' => $this->summary->period,
                ]),
            ],
        );
    }
}
