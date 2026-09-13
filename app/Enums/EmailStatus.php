<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an outgoing email has got to.
 *
 * Moves forward only. Webhooks arrive out of order and SMTP2Go retries them,
 * so a late "delivered" must not paper over a bounce that came first — see
 * {@see self::canAdvanceTo()}.
 */
enum EmailStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case SoftBounced = 'soft_bounced';
    case Delivered = 'delivered';
    case Unsubscribed = 'unsubscribed';
    case Rejected = 'rejected';
    case HardBounced = 'hard_bounced';
    case Spam = 'spam';
    case Failed = 'failed';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::SoftBounced => 'Delayed',
            self::Delivered => 'Delivered',
            self::Unsubscribed => 'Unsubscribed',
            self::Rejected => 'Rejected',
            self::HardBounced => 'Bounced',
            self::Spam => 'Marked as spam',
            self::Failed => 'Failed to send',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Sending, self::Sent => 'info',
            self::SoftBounced => 'warning',
            self::Delivered => 'success',
            self::Unsubscribed => 'secondary',
            self::Rejected, self::HardBounced, self::Spam, self::Failed => 'danger',
        };
    }

    /**
     * Whether the recipient did not get the message, which is what a person
     * reading an invoice needs to be told about.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::Rejected, self::HardBounced, self::Spam, self::Failed], true);
    }

    /**
     * A soft bounce sits below delivery because SMTP2Go keeps retrying it and
     * usually succeeds. Everything past delivery is something the recipient's
     * server or the recipient did after the fact, and outranks it.
     */
    public function canAdvanceTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Sending => 0,
            self::Sent => 1,
            self::SoftBounced => 2,
            self::Delivered => 3,
            self::Unsubscribed => 4,
            self::Rejected, self::HardBounced => 5,
            self::Spam => 6,
            self::Failed => 7,
        };
    }
}
