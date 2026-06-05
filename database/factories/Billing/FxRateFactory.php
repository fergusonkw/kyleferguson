<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\FxRateSource;
use App\Models\Billing\FxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxRate>
 */
final class FxRateFactory extends Factory
{
    protected $model = FxRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency_from' => 'USD',
            'currency_to' => 'CAD',
            'period' => now()->format('Y-m'),
            'rate' => fake()->randomFloat(8, 1.20, 1.45),
            'source' => FxRateSource::BankOfCanada,
            'fetched_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => ['period' => $period]);
    }

    public function usdToUsd(): static
    {
        return $this->state(fn (array $attributes): array => [
            'currency_from' => 'USD',
            'currency_to' => 'USD',
            'rate' => 1.0,
        ]);
    }
}
