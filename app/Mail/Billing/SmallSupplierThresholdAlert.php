<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Enums\Billing\ThresholdLevel;
use App\Services\Billing\Dto\ThresholdAssessment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operator-facing alert: a legal entity has reached the warning level for, or
 * passed, the GST/HST small-supplier threshold.
 */
final class SmallSupplierThresholdAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ThresholdAssessment $assessment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %s — GST/HST small-supplier threshold',
                $this->assessment->entity->name,
                $this->assessment->level === ThresholdLevel::Exceeded ? 'Threshold passed' : 'Approaching the threshold',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.small-supplier-threshold',
            text: 'emails.billing.small-supplier-threshold-text',
            with: [
                'assessment' => $this->assessment,
                'exceeded' => $this->assessment->level === ThresholdLevel::Exceeded,
                'dashboardUrl' => route('admin.billing.index'),
            ],
        );
    }
}
