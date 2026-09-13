<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One report from SMTP2Go about an email: delivered, bounced, opened, …
 *
 * Append-only, with the payload kept as it arrived so the headline status on
 * the message can always be traced back to what the provider actually said.
 *
 * @property int $id
 * @property int $email_message_id
 * @property string $event
 * @property string|null $recipient
 * @property array<string, mixed> $payload
 * @property string $fingerprint
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read EmailMessage $emailMessage
 *
 * @method static \Database\Factories\EmailEventFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class EmailEvent extends Model
{
    /** @use HasFactory<\Database\Factories\EmailEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'email_message_id',
        'event',
        'recipient',
        'payload',
        'fingerprint',
        'occurred_at',
    ];

    /** @return BelongsTo<EmailMessage, $this> */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function label(): string
    {
        return match ($this->event) {
            'processed' => 'Accepted by SMTP2Go',
            'delivered' => 'Delivered',
            'bounce' => mb_strtolower((string) ($this->payload['bounce'] ?? '')) === 'soft' ? 'Soft bounce' : 'Bounced',
            'reject' => 'Rejected',
            'spam' => 'Marked as spam',
            'unsubscribe' => 'Unsubscribed',
            'resubscribe' => 'Resubscribed',
            'open' => 'Opened',
            'click' => 'Clicked a link',
            default => Str::headline($this->event),
        };
    }

    /**
     * The one line worth reading out of the payload, so nobody has to open
     * the JSON to learn why a message bounced or where it was opened.
     */
    public function summary(): ?string
    {
        return match ($this->event) {
            'bounce', 'reject', 'spam' => $this->reason(),
            'open' => $this->joined(['client', 'client-os']),
            'click' => $this->text('url'),
            default => null,
        };
    }

    /**
     * Why the provider refused it, in the receiving server's own words where
     * SMTP2Go passed them on.
     */
    public function reason(): ?string
    {
        $reason = $this->text('message') ?? $this->text('context');
        $host = $this->text('host');

        if ($reason === null) {
            return $host !== null ? "Refused by {$host}" : null;
        }

        return $host !== null ? "{$reason} ({$host})" : $reason;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    private function text(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function joined(array $keys): ?string
    {
        $parts = array_filter(array_map($this->text(...), $keys));

        return $parts === [] ? null : implode(' on ', $parts);
    }
}
