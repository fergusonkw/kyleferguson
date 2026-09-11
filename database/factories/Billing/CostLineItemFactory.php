<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\CostCategory;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $amount = fake()->randomFloat(2, 5, 500);

        return [
            'cost_provider_id' => CostProvider::factory(),
            'provider_resource_id' => null,
            'project_id' => null,
            'source_payload_id' => null,
            'period' => now()->format('Y-m'),
            'category' => CostCategory::Compute,
            'description' => fake()->words(3, true),
            'source_amount' => $amount,
            'source_currency' => 'USD',
            'usd_amount' => $amount,
            'usd_tax' => 0,
            'source_reference' => null,
            'metadata' => null,
            'line_hash' => CostLineItem::makeLineHash([(string) Str::uuid()]),
            'attributed_at' => null,
            'derived_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => $period,
        ]);
    }

    public function ofCategory(CostCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }

    public function forResource(ProviderResource $resource): static
    {
        return $this->state(fn (array $attributes): array => [
            'cost_provider_id' => $resource->cost_provider_id,
            'provider_resource_id' => $resource->id,
        ]);
    }

    public function attributedTo(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
            'attributed_at' => now(),
        ]);
    }

    public function usd(float $amount, float $tax = 0.0): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_amount' => $amount,
            'source_currency' => 'USD',
            'usd_amount' => $amount,
            'usd_tax' => $tax,
        ]);
    }
}
