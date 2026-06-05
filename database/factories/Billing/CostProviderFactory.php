<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\SyncStatus;
use App\Models\Billing\Business;
use App\Models\Billing\CostProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CostProvider>
 */
final class CostProviderFactory extends Factory
{
    protected $model = CostProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'slug' => CostProviderSlug::DigitalOcean,
            'display_name' => 'DigitalOcean',
            'credentials' => [
                'token' => 'dop_v1_'.Str::random(64),
            ],
            'enabled' => true,
            'last_synced_at' => null,
            'last_sync_status' => SyncStatus::Never,
            'last_sync_error' => null,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_synced_at' => now(),
            'last_sync_status' => SyncStatus::Success,
        ]);
    }

    public function failed(string $error = 'Connection refused'): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_sync_status' => SyncStatus::Failed,
            'last_sync_error' => $error,
        ]);
    }
}
