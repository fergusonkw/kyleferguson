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
        $payload = ['invoice_items' => [], 'amount' => '0.00'];

        return [
            'cost_provider_id' => CostProvider::factory(),
            'period' => now()->format('Y-m'),
            'raw_payload' => $payload,
            'content_hash' => hash('sha256', json_encode($payload)),
            'fetched_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => ['period' => $period]);
    }
}
