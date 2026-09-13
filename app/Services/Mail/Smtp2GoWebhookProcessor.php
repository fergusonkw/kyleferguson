<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\EmailStatus;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns SMTP2Go's webhook reports into email events and moves each email's
 * status along.
 *
 * A report is matched to an email by SMTP2Go's `email_id`, captured when the
 * email was sent. That is also what keeps this application out of another's
 * mail: an SMTP2Go account shared with Tracker Pull can deliver its reports
 * here, and they are ignored because no email here carries their id.
 *
 * Field names follow SMTP2Go's documented webhook payload: `event`,
 * `email_id`, `rcpt`, `time`, and `bounce` ("hard" or "soft") on a bounce.
 */
final class Smtp2GoWebhookProcessor
{
    /**
     * @param  array<array-key, mixed>  $body  one report, or a list of them
     * @return array{received: int, recorded: int}
     */
    public function handle(array $body): array
    {
        $reports = array_is_list($body) ? array_filter($body, is_array(...)) : [$body];
        $recorded = 0;

        foreach ($reports as $report) {
            if ($this->process($report) !== null) {
                $recorded++;
            }
        }

        return ['received' => count($reports), 'recorded' => $recorded];
    }

    /**
     * Record one report against its email, or null when it is about an email
     * this application did not send.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function process(array $payload): ?EmailEvent
    {
        $emailId = $payload['email_id'] ?? null;
        $event = is_string($payload['event'] ?? null) ? mb_strtolower(trim($payload['event'])) : '';

        if (! is_string($emailId) || $emailId === '' || $event === '') {
            return null;
        }

        $message = EmailMessage::query()->where('provider_message_id', $emailId)->first();

        if ($message === null) {
            return null;
        }

        $payload = $this->redact($payload);

        return DB::transaction(function () use ($message, $event, $payload): EmailEvent {
            $record = EmailEvent::query()->createOrFirst(
                ['fingerprint' => $this->fingerprint($payload)],
                [
                    'email_message_id' => $message->id,
                    'event' => mb_substr($event, 0, 32),
                    'recipient' => is_string($payload['rcpt'] ?? null) ? mb_substr($payload['rcpt'], 0, 320) : null,
                    'payload' => $payload,
                    'occurred_at' => $this->occurredAt($payload),
                ],
            );

            // A retry of a report already recorded changes nothing.
            if ($record->wasRecentlyCreated) {
                $this->advance($message, $record);
            }

            return $record;
        });
    }

    private function advance(EmailMessage $message, EmailEvent $event): void
    {
        $message = EmailMessage::query()->lockForUpdate()->findOrFail($message->id);
        $next = $this->statusFor($event);
        $changes = [];

        if ($message->last_event_at === null || $event->occurred_at->greaterThan($message->last_event_at)) {
            $changes['last_event_at'] = $event->occurred_at;
        }

        if ($next !== null && $message->status->canAdvanceTo($next)) {
            $changes['status'] = $next;
            $changes['error'] = $next === EmailStatus::Delivered ? null : $event->reason();
        }

        if ($changes !== []) {
            $message->update($changes);
        }
    }

    /**
     * Opens, clicks and SMTP2Go's own "processed" are kept as events but say
     * nothing about whether the message arrived.
     */
    private function statusFor(EmailEvent $event): ?EmailStatus
    {
        return match ($event->event) {
            'delivered' => EmailStatus::Delivered,
            'bounce' => mb_strtolower((string) ($event->payload['bounce'] ?? '')) === 'soft'
                ? EmailStatus::SoftBounced
                : EmailStatus::HardBounced,
            'reject' => EmailStatus::Rejected,
            'spam' => EmailStatus::Spam,
            'unsubscribe' => EmailStatus::Unsubscribed,
            default => null,
        };
    }

    /**
     * `auth` names the SMTP user or API key that sent the email. An API key is
     * a credential, and the payload is shown on the email log.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redact(array $payload): array
    {
        $auth = $payload['auth'] ?? null;

        if (is_string($auth) && mb_strlen($auth) > 12) {
            $payload['auth'] = Str::mask($auth, '*', 4, mb_strlen($auth) - 8);
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function occurredAt(array $payload): CarbonImmutable
    {
        $time = $payload['time'] ?? $payload['sendtime'] ?? null;

        try {
            if (is_numeric($time)) {
                return CarbonImmutable::createFromTimestamp((int) $time, 'UTC');
            }

            if (is_string($time) && $time !== '') {
                return CarbonImmutable::parse($time, 'UTC');
            }
        } catch (Throwable) {
            // An unreadable time is recorded as the moment it arrived.
        }

        return CarbonImmutable::now();
    }
}
