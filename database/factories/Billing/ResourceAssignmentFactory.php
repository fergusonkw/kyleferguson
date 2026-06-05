<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceAssignment>
 */
final class ResourceAssignmentFactory extends Factory
{
    protected $model = ResourceAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_resource_id' => ProviderResource::factory(),
            'project_id' => null,
            'provider_project_uuid' => null,
            'observed_from' => now(),
            'observed_to' => null,
        ];
    }

    public function closed(?DateTimeInterface $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'observed_to' => $at ?? now(),
        ]);
    }
}
