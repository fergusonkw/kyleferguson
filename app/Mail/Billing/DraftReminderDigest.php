<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Billing\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Operator-facing: drafts are sitting unapproved.
 *
 * An unapproved draft is unbilled work, so this repeats daily until the draft
 * is approved or voided rather than firing once and being missed.
 */
final class DraftReminderDigest extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Billing\Invoice>  $drafts
     */
    public function __construct(
        public Business $business,
        public Collection $drafts,
    ) {}

    public function envelope(): Envelope
    {
        $oldest = $this->drafts->min('created_at');

        return new Envelope(
            subject: sprintf(
                '[%s] %d draft invoice%s awaiting approval%s',
                $this->business->name,
                $this->drafts->count(),
                $this->drafts->count() === 1 ? '' : 's',
                $oldest !== null ? ' — oldest '.$oldest->diffForHumans() : '',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.draft-reminder-digest',
            text: 'emails.billing.draft-reminder-digest-text',
            with: [
                'businessName' => $this->business->name,
                'drafts' => $this->drafts,
                'total' => $this->drafts->sum(fn ($i): float => (float) $i->total),
                'listUrl' => route('admin.billing.invoices.index'),
            ],
        );
    }
}
