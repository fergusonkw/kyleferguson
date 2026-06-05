<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomFloat(2, 50, 1000),
            'received_at' => now(),
            'method' => PaymentMethod::Etransfer,
            'reference' => fake()->optional()->numerify('TXN-######'),
            'notes' => null,
            'recorded_by_user_id' => null,
        ];
    }

    public function cheque(): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => PaymentMethod::Cheque,
        ]);
    }
}
