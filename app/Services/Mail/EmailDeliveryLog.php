<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * The outbound half of the email log: a row for every message the
 * application sends, written as it goes out.
 *
 * Driven by Laravel's mail events rather than a wrapper around sending, so a
 * `Mail::to()->send()` added anywhere is recorded without anyone having to
 * remember to route it through here. A mailable says what it is about with
 * Laravel's own message metadata ({@see self::relatedTo()}), which is how an
 * invoice finds the emails that carried it.
 *
 * Keeping the record must never cost the send. A write that fails is reported
 * and the email goes anyway — a deploy that runs ahead of its migration should
 * not stop invoices going out.
 */
final class EmailDeliveryLog
{
    /**
     * Stamped on the outgoing message so the transport and the sent event can
     * find the row again. The SMTP2Go transport forwards Reply-To and no other
     * header, so this never reaches the recipient.
     */
    public const LOG_ID_HEADER = 'X-Email-Log-Id';

    /**
     * Metadata for a mailable's envelope that ties its log row to a record.
     *
     * @return array{related_type: string, related_id: string}
     */
    public static function relatedTo(Model $record): array
    {
        return [
            'related_type' => $record->getMorphClass(),
            'related_id' => (string) $record->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  the view data Laravel passes with its mail events
     */
    public function recordSending(Email $message, array $data): void
    {
        try {
            $metadata = $this->metadataOf($message);
            $withheld = $this->withholdsContent($data);

            $row = EmailMessage::query()->create([
                'mailer' => is_string($data['mailer'] ?? null) ? $data['mailer'] : null,
                'mailable_class' => $this->classOf($data),
                'related_type' => $metadata['related_type'] ?? null,
                'related_id' => $metadata['related_id'] ?? null,
                'metadata' => Arr::except($metadata, ['related_type', 'related_id']) ?: null,
                'from_address' => $this->addresses($message->getFrom()),
                'to_address' => $this->addresses($message->getTo()),
                'subject' => mb_substr((string) $message->getSubject(), 0, 255),
                'status' => EmailStatus::Sending,
                'html_body' => $withheld ? null : $this->body($message->getHtmlBody()),
                'text_body' => $withheld ? null : $this->body($message->getTextBody()),
                'content_withheld' => $withheld,
                'attachments' => $this->attachmentsOf($message) ?: null,
                'sent_by_user_id' => Auth::id(),
            ]);

            $message->getHeaders()->addTextHeader(self::LOG_ID_HEADER, (string) $row->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The transport accepted the message.
     */
    public function recordSent(Email $message, ?string $providerMessageId): void
    {
        $this->update($message, [
            'status' => EmailStatus::Sent->value,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * The transport refused the message or could not be reached.
     */
    public function recordFailed(Email $message, string $error): void
    {
        $this->update($message, [
            'status' => EmailStatus::Failed->value,
            'error' => mb_substr($error, 0, 5000),
        ]);
    }

    /**
     * Only a row still sending moves: whatever already happened to a message
     * is not undone by a late report about how it left.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function update(Email $message, array $attributes): void
    {
        $id = $message->getHeaders()->getHeaderBody(self::LOG_ID_HEADER);

        if (! is_string($id) || ! ctype_digit($id)) {
            return;
        }

        try {
            EmailMessage::query()
                ->whereKey((int) $id)
                ->where('status', EmailStatus::Sending->value)
                ->update($attributes);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function metadataOf(Email $message): array
    {
        $metadata = [];

        foreach ($message->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
            }
        }

        return $metadata;
    }

    /**
     * A body carrying a credential — a password reset link — is not kept, so
     * the log cannot become a way to sign in as someone else.
     *
     * @param  array<string, mixed>  $data
     */
    private function withholdsContent(array $data): bool
    {
        $class = $this->classOf($data);

        if ($class === null) {
            return false;
        }

        /** @var list<class-string> $withheld */
        $withheld = config('mail.delivery_log.withhold_content', []);

        foreach ($withheld as $withheldClass) {
            if (is_a($class, $withheldClass, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function classOf(array $data): ?string
    {
        $class = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;

        return is_string($class) ? $class : null;
    }

    /**
     * @param  list<Address>  $addresses
     */
    private function addresses(array $addresses): string
    {
        $formatted = array_map(fn (Address $address): string => $address->getName() !== ''
            ? "{$address->getName()} <{$address->getAddress()}>"
            : $address->getAddress(), $addresses);

        return mb_substr(implode(', ', $formatted), 0, 1000);
    }

    /**
     * @param  resource|string|null  $body
     */
    private function body(mixed $body): ?string
    {
        return is_string($body) ? $body : null;
    }

    /**
     * What was attached, not the bytes: the invoice PDF is reproducible from
     * its captured document, and the hash lets a copy the client sends back be
     * matched to this email.
     *
     * @return list<array{filename: string|null, content_type: string, size: int, sha256: string}>
     */
    private function attachmentsOf(Email $message): array
    {
        return array_map(function (DataPart $attachment): array {
            $bytes = $attachment->getBody();

            return [
                'filename' => $attachment->getFilename(),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        }, $message->getAttachments());
    }
}
