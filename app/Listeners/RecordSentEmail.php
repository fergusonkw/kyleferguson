<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Mail\EmailDeliveryLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Moves the log row on once the transport has accepted the message, keeping
 * the id SMTP2Go gave it.
 */
final class RecordSentEmail
{
    public function __construct(private readonly EmailDeliveryLog $log) {}

    public function handle(MessageSent $event): void
    {
        $this->log->recordSent($event->message, $this->providerMessageId($event));
    }

    /**
     * Only SMTP2Go's id is worth keeping, because it is what SMTP2Go's
     * webhooks quote back. Any other transport reports the Message-ID it made
     * up itself, which no webhook will ever mention.
     */
    private function providerMessageId(MessageSent $event): ?string
    {
        $mailer = $event->data['mailer'] ?? null;

        if (! is_string($mailer) || config("mail.mailers.{$mailer}.transport") !== 'smtp2go') {
            return null;
        }

        return $event->sent->getMessageId();
    }
}
