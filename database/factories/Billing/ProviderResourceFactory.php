<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderResource>
 */
final class ProviderResourceFactory extends Factory
{
    protected $model = ProviderResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'cost_provider_id' => CostProvider::factory(),
            'project_id' => null,
            'provider_resource_id' => (string) fake()->numberBetween(100000000, 999999999),
            'resource_type' => 'droplet',
            'name' => fake()->words(2, true),
            'provider_project_uuid' => (string) Str::uuid(),
            'metadata' => [],
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ];
    }

    public function attributedTo(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
            'provider_project_uuid' => $project->do_project_uuid,
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'resource_type' => $type,
        ]);
    }
}
