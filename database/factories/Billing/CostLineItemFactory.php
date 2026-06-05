<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\BillingCategory;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\ProviderBillingPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostLineItem>
 */
final class CostLineItemFactory extends Factory
{
    protected $model = CostLineItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = CostProvider::factory()->create();

        return [
            'cost_provider_id' => $provider->id,
            'provider_resource_id' => null,
            'project_id' => null,
            'source_payload_id' => ProviderBillingPayload::factory()->for($provider),
            'period' => now()->format('Y-m'),
            'usd_amount' => fake()->randomFloat(6, 1, 500),
            'usd_tax' => 0,
            'category' => BillingCategory::Droplet,
            'description' => fake()->words(3, true),
            'derived_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => ['period' => $period]);
    }

    public function overhead(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => BillingCategory::Overhead,
            'provider_resource_id' => null,
            'project_id' => null,
        ]);
    }
}
