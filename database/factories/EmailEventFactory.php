<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailEvent>
 */
final class EmailEventFactory extends Factory
{
    protected $model = EmailEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'event' => 'delivered',
            'recipient' => fake()->safeEmail(),
            'payload' => ['event' => 'delivered'],
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'occurred_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reporting(string $event, array $payload = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
            'payload' => ['event' => $event, ...$payload],
        ]);
    }
}
