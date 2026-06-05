<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\BillingCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cost_provider_id
 * @property int|null $provider_resource_id
 * @property int|null $project_id
 * @property int $source_payload_id
 * @property string $period
 * @property float $usd_amount
 * @property float $usd_tax
 * @property BillingCategory $category
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $derived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CostProvider $costProvider
 * @property-read ProviderResource|null $providerResource
 * @property-read Project|null $project
 * @property-read ProviderBillingPayload $sourcePayload
 *
 * @method static \Database\Factories\Billing\CostLineItemFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class CostLineItem extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\CostLineItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'cost_provider_id',
        'provider_resource_id',
        'project_id',
        'source_payload_id',
        'period',
        'usd_amount',
        'usd_tax',
        'category',
        'description',
        'derived_at',
    ];

    /** @return BelongsTo<CostProvider, $this> */
    public function costProvider(): BelongsTo
    {
        return $this->belongsTo(CostProvider::class);
    }

    /** @return BelongsTo<ProviderResource, $this> */
    public function providerResource(): BelongsTo
    {
        return $this->belongsTo(ProviderResource::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProviderBillingPayload, $this> */
    public function sourcePayload(): BelongsTo
    {
        return $this->belongsTo(ProviderBillingPayload::class, 'source_payload_id');
    }

    public function isAttributed(): bool
    {
        return $this->project_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usd_amount' => 'float',
            'usd_tax' => 'float',
            'category' => BillingCategory::class,
            'derived_at' => 'datetime',
        ];
    }
}
