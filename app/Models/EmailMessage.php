<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An email the application sent, and what became of it.
 *
 * Written by {@see \App\Services\Mail\EmailDeliveryLog} as the message goes
 * out, and moved on by SMTP2Go's webhooks as they report back.
 *
 * @property int $id
 * @property string|null $mailer
 * @property string|null $mailable_class
 * @property string|null $related_type
 * @property int|null $related_id
 * @property array<string, string>|null $metadata
 * @property string|null $from_address
 * @property string $to_address
 * @property string|null $subject
 * @property EmailStatus $status
 * @property string|null $provider_message_id
 * @property string|null $html_body
 * @property string|null $text_body
 * @property bool $content_withheld
 * @property list<array{filename: string|null, content_type: string, size: int, sha256: string}>|null $attachments
 * @property string|null $error
 * @property int|null $sent_by_user_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $related
 * @property-read User|null $sentBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EmailEvent> $events
 *
 * @method static \Database\Factories\EmailMessageFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class EmailMessage extends Model
{
    /** @use HasFactory<\Database\Factories\EmailMessageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'mailer',
        'mailable_class',
        'related_type',
        'related_id',
        'metadata',
        'from_address',
        'to_address',
        'subject',
        'status',
        'provider_message_id',
        'html_body',
        'text_body',
        'content_withheld',
        'attachments',
        'error',
        'sent_by_user_id',
        'sent_at',
        'last_event_at',
    ];

    /**
     * Bodies belong on the page that shows one message, not in every list.
     *
     * @var list<string>
     */
    protected $hidden = [
        'html_body',
        'text_body',
    ];

    /**
     * What the email was about — the invoice, for a client invoice email.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /**
     * What SMTP2Go reported, in the order it happened.
     *
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * When the recipient first opened it, if SMTP2Go's open tracking caught it.
     * Tracking pixels are blocked often enough that no open proves nothing.
     */
    public function firstOpenedAt(): ?Carbon
    {
        return $this->events->firstWhere('event', 'open')?->occurred_at;
    }

    /**
     * Whether the message left for a real recipient rather than a log file.
     */
    public function wasDelivered(): bool
    {
        return ! in_array($this->mailer, ['log', 'array'], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'metadata' => 'array',
            'attachments' => 'array',
            'content_withheld' => 'boolean',
            'sent_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }
}
