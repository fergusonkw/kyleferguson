<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\RecurringCadence;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
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
            'label' => 'Laravel Forge',
            'amount' => 19.00,
            'currency' => 'CAD',
            'cadence' => RecurringCadence::Monthly,
            'active_from' => now()->startOfMonth()->subYear(),
            'active_to' => null,
            'notes' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'client_id' => null,
            'project_id' => $project->id,
        ]);
    }

    public function cadence(RecurringCadence $cadence): static
    {
        return $this->state(fn (array $attributes): array => [
            'cadence' => $cadence,
        ]);
    }

    public function amount(float $amount, string $currency = 'CAD'): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    public function window(string $from, ?string $to = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'active_from' => $from,
            'active_to' => $to,
        ]);
    }
}
