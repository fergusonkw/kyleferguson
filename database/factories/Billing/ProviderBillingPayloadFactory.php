<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Models\Billing\CostProvider;
use App\Models\Billing\ProviderBillingPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderBillingPayload>
 */
final class ProviderBillingPayloadFactory extends Factory
{
    protected $model = ProviderBillingPayload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['cycle_used' => fake()->numberBetween(1, 9000)];

        return [
            'cost_provider_id' => CostProvider::factory(),
            'period' => now()->format('Y-m'),
            'source_key' => null,
            'raw_payload' => $payload,
            'content_hash' => ProviderBillingPayload::hashPayload($payload),
            'fetched_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => $period,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): static
    {
        return $this->state(fn (array $attributes): array => [
            'raw_payload' => $payload,
            'content_hash' => ProviderBillingPayload::hashPayload($payload),
        ]);
    }
}
