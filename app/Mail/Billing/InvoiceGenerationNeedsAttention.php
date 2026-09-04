<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Business;
use App\Services\Billing\BillingPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operator-facing: scheduled generation could not produce what it should have.
 *
 * Silence is the dangerous failure here — a month where no drafts appear looks
 * exactly like a month with nothing to bill, so a skipped run has to announce
 * itself.
 */
final class InvoiceGenerationNeedsAttention extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string>  $failures  client name => why it failed
     */
    public function __construct(
        public Business $business,
        public string $period,
        public array $failures,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] Invoice generation needs attention — %s',
                $this->business->name,
                BillingPeriod::label($this->period),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.invoice-generation-needs-attention',
            text: 'emails.billing.invoice-generation-needs-attention-text',
            with: [
                'businessName' => $this->business->name,
                'periodLabel' => BillingPeriod::label($this->period),
                'failures' => $this->failures,
                'listUrl' => route('admin.billing.invoices.index'),
            ],
        );
    }
}
