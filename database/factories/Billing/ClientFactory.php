<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
final class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'billing_address' => fake()->address(),
            'billing_currency' => 'CAD',
            'status' => ClientStatus::Active,
            'default_markup_type' => MarkupType::Passthrough,
            'default_markup_value' => 0,
            'notes' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ClientStatus::Archived,
        ]);
    }

    public function withMarkup(MarkupType $type, float $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_markup_type' => $type,
            'default_markup_value' => $value,
        ]);
    }
}
