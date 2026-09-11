<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\LegalEntityType;
use App\Models\Billing\LegalEntity;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
final class LegalEntityFactory extends Factory
{
    protected $model = LegalEntity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'entity_type' => LegalEntityType::SoleProprietorship,
            'tax_registered_from' => null,
            'threshold_warning_percent' => 80,
        ];
    }

    public function corporation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => fake()->unique()->company().' Inc.',
            'entity_type' => LegalEntityType::Corporation,
        ]);
    }

    public function taxRegistered(?DateTimeInterface $from = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_registered_from' => $from ?? now()->startOfYear(),
        ]);
    }
}
