<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Services\Mail\EmailDeliveryLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Motomedialab\Smtp2Go\Exceptions\Smtp2GoException;
use Motomedialab\Smtp2Go\Transports\Smtp2GoTransport;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * The vendor SMTP2Go transport, changed where the email log needs it to be.
 *
 *  1. SMTP2Go's `email_id` is kept. The send API returns it and the vendor
 *     drops it, yet it is the one thing SMTP2Go's webhooks quote back — so it
 *     becomes the sent message's id, where {@see \App\Listeners\RecordSentEmail}
 *     reads it.
 *  2. A failed send marks its log row failed before the exception carries on,
 *     so the log never shows a message that died as still sending.
 *  3. The API key travels in a header, and a failure's context describes the
 *     message instead of containing it. Laravel writes exception context into
 *     the log and Sentry, and the vendor put the API key and every attachment
 *     — a client's invoice — there.
 *
 * Sending is otherwise the vendor's, except that an attachment's MIME type is
 * sent whole: the vendor sent only the first half ("application" for a PDF).
 * fergusonkw/laravel-smtp2go carries (1) and (3), so pointing composer at that
 * fork would leave only (2) and the MIME type here.
 */
final class TrackingSmtp2GoTransport extends Smtp2GoTransport
{
    public function __construct(private readonly EmailDeliveryLog $log)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $emailId = $this->post($email);
        } catch (Throwable $e) {
            $this->log->recordFailed($email, $e->getMessage());

            throw $e;
        }

        if ($emailId !== null) {
            $message->setMessageId($emailId);
        }
    }

    /**
     * @return string|null SMTP2Go's id for the message
     */
    private function post(Email $email): ?string
    {
        $data = collect([
            'to' => $this->sanitiseAddresses($email->getTo())->all(),
            'cc' => $this->sanitiseAddresses($email->getCc())->all(),
            'bcc' => $this->sanitiseAddresses($email->getBcc())->all(),
            'sender' => $this->sanitiseAddresses($email->getFrom())->first(),
            'subject' => $email->getSubject(),
            'html_body' => $email->getHtmlBody(),
            'text_body' => $email->getTextBody(),
            'custom_headers' => collect([
                [
                    'header' => 'Reply-To',
                    'value' => $this->sanitiseAddresses($email->getReplyTo())->first(),
                ],
            ])->filter(fn (array $header): bool => filled($header['value']))->values()->all(),
            'attachments' => collect($email->getAttachments())->map(fn (DataPart $attachment): array => [
                'filename' => $attachment->getFilename(),
                'fileblob' => $attachment->bodyToString(),
                'mimetype' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ])->all(),
        ])->filter()->all();

        $response = Http::timeout(60)
            ->withHeaders(['X-Smtp2go-Api-Key' => (string) config('mail.mailers.smtp2go.api_key')])
            ->post($this->endpoint, $data);

        if (! $response->successful() || $response->json('data.succeeded') < 1) {
            throw Smtp2GoException::make('SMTP2Go refused the message: '.$this->reasonFor($response), $response->status())
                ->setContext([
                    'status' => $response->status(),
                    'message' => $this->summarise($data),
                    'error' => $response->json(),
                ]);
        }

        $emailId = $response->json('data.email_id');

        return is_string($emailId) && $emailId !== '' ? $emailId : null;
    }

    /**
     * SMTP2Go's own explanation, which is what the operator needs to see when
     * an invoice does not go.
     */
    private function reasonFor(Response $response): string
    {
        $failures = $response->json('data.failures');
        $reason = $response->json('data.error')
            ?? (is_array($failures) && $failures !== [] ? implode('; ', array_map(strval(...), $failures)) : null);

        return is_string($reason) && $reason !== ''
            ? $reason
            : "HTTP {$response->status()} with no explanation.";
    }

    /**
     * Enough to diagnose a failure without writing the message, its
     * recipients or its attachments into the log.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function summarise(array $data): array
    {
        return [
            'sender' => $data['sender'] ?? null,
            'subject' => $data['subject'] ?? null,
            'recipients' => [
                'to' => count($data['to'] ?? []),
                'cc' => count($data['cc'] ?? []),
                'bcc' => count($data['bcc'] ?? []),
            ],
            'attachments' => array_map(fn (array $attachment): array => [
                'filename' => $attachment['filename'],
                'mimetype' => $attachment['mimetype'],
            ], $data['attachments'] ?? []),
        ];
    }
}
