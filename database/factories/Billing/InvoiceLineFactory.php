<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
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
            'line_type' => InvoiceLineType::Hosting,
            'amount' => fake()->randomFloat(2, 10, 500),
            'source_reference' => null,
            'display_order' => 0,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'line_type' => InvoiceLineType::Manual,
        ]);
    }

    public function tax(): static
    {
        return $this->state(fn (array $attributes): array => [
            'line_type' => InvoiceLineType::Tax,
            'label' => 'GST/HST',
        ]);
    }
}
