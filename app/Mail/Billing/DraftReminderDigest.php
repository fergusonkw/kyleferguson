<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class DraftReminderDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Billing\Invoice>  $drafts
     */
    public function __construct(
        public readonly Business $business,
        public readonly Collection $drafts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pending Invoice Drafts — '.$this->business->name.' ('.$this->drafts->count().' awaiting approval)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.draft-reminder-digest',
        );
    }
}
