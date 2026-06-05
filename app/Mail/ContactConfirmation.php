<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ContactConfirmation extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{name: string, email: string, company: string, type: string, message: string, copyToSelf: bool}  $payload
     */
    public function __construct(
        public string $ticket,
        public string $date,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your message to Kyle Ferguson — {$this->ticket}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-confirmation',
            text: 'emails.contact-confirmation-text',
            with: [
                'ticket' => $this->ticket,
                'date' => $this->date,
                'name' => $this->payload['name'],
                'email' => $this->payload['email'],
                'company' => $this->payload['company'],
                'type' => $this->payload['type'],
                'body' => $this->payload['message'],
            ],
        );
    }
}
