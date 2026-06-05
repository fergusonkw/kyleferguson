<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\Cadence;
use App\Models\Billing\Client;
use App\Models\Billing\RecurringLineTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringLineTemplate>
 */
final class RecurringLineTemplateFactory extends Factory
{
    protected $model = RecurringLineTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'label' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 25, 500),
            'currency' => 'CAD',
            'cadence' => Cadence::Monthly,
            'active_from' => now()->startOfYear(),
            'active_to' => null,
        ];
    }

    public function quarterly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cadence' => Cadence::Quarterly,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cadence' => Cadence::Annual,
        ]);
    }
}
