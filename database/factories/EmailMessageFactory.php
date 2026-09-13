<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An email SMTP2Go has accepted and not yet reported on.
 *
 * @extends Factory<EmailMessage>
 */
final class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailer' => 'smtp2go',
            'mailable_class' => null,
            'from_address' => 'Kyle Ferguson <hello@kyleferguson.ca>',
            'to_address' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'status' => EmailStatus::Sent,
            'provider_message_id' => Str::random(6).'-'.Str::random(6).'-'.Str::random(2),
            'html_body' => '<p>'.fake()->sentence().'</p>',
            'text_body' => fake()->sentence(),
            'sent_at' => now(),
        ];
    }

    public function about(Model $related): static
    {
        return $this->state(fn (array $attributes): array => [
            'related_type' => $related->getMorphClass(),
            'related_id' => $related->getKey(),
        ]);
    }

    public function status(EmailStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function withheld(): static
    {
        return $this->state(fn (array $attributes): array => [
            'html_body' => null,
            'text_body' => null,
            'content_withheld' => true,
        ]);
    }
}
