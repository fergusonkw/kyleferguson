<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $cost_provider_id
 * @property string $period
 * @property array<string, mixed> $raw_payload
 * @property string $content_hash
 * @property \Illuminate\Support\Carbon $fetched_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CostProvider $costProvider
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostLineItem> $costLineItems
 *
 * @method static \Database\Factories\Billing\ProviderBillingPayloadFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class ProviderBillingPayload extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\ProviderBillingPayloadFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'cost_provider_id',
        'period',
        'raw_payload',
        'content_hash',
        'fetched_at',
    ];

    /** @return BelongsTo<CostProvider, $this> */
    public function costProvider(): BelongsTo
    {
        return $this->belongsTo(CostProvider::class);
    }

    /** @return HasMany<CostLineItem, $this> */
    public function costLineItems(): HasMany
    {
        return $this->hasMany(CostLineItem::class, 'source_payload_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
