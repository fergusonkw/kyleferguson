<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvoiceGenerationNeedsAttention extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Business $business,
        public readonly string $period,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice Generation Needs Attention — '.$this->business->name.' '.$this->period,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.invoice-generation-needs-attention',
        );
    }
}
