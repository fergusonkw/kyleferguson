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
use Illuminate\Support\Collection;

/**
 * Operator-facing: this period's drafts have been generated and are waiting.
 *
 * @property Collection<int, \App\Models\Billing\Invoice> $invoices
 */
final class InvoiceReadyForReview extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Billing\Invoice>  $invoices
     */
    public function __construct(
        public Business $business,
        public string $period,
        public Collection $invoices,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %d invoice%s ready for review — %s',
                $this->business->name,
                $this->invoices->count(),
                $this->invoices->count() === 1 ? '' : 's',
                BillingPeriod::label($this->period),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.invoice-ready-for-review',
            text: 'emails.billing.invoice-ready-for-review-text',
            with: [
                'businessName' => $this->business->name,
                'periodLabel' => BillingPeriod::label($this->period),
                'invoices' => $this->invoices,
                'total' => $this->invoices->sum(fn ($i): float => (float) $i->total),
                'listUrl' => route('admin.billing.invoices.index'),
            ],
        );
    }
}
