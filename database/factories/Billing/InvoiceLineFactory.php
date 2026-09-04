<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
final class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'parent_id' => null,
            'project_id' => null,
            'label' => fake()->words(3, true),
            'line_type' => InvoiceLineType::Manual,
            'amount' => fake()->randomFloat(2, 10, 500),
            'cost_basis_usd' => null,
            'source_reference' => null,
            'is_display_only' => false,
            'display_order' => 0,
            'metadata' => null,
        ];
    }

    public function ofType(InvoiceLineType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'line_type' => $type,
        ]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => $amount,
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
        ]);
    }

    /**
     * A sub-item under a parent hosting line: shown for transparency, already
     * counted inside the parent's amount.
     */
    public function subItemOf(InvoiceLine $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'invoice_id' => $parent->invoice_id,
            'parent_id' => $parent->id,
            'is_display_only' => true,
        ]);
    }
}
