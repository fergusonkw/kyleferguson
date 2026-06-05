<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->words(2, true),
            'do_project_uuid' => null,
            'markup_type' => null,
            'markup_value' => null,
            'status' => ProjectStatus::Active,
            'terminated_at' => null,
            'notes' => null,
        ];
    }

    public function linkedToDigitalOcean(?string $uuid = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'do_project_uuid' => $uuid ?? (string) Str::uuid(),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Terminated,
            'terminated_at' => now(),
        ]);
    }

    public function withMarkupOverride(MarkupType $type, float $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'markup_type' => $type,
            'markup_value' => $value,
        ]);
    }
}
