<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A verbatim provider API response for one billing period, kept alongside the
 * records derived from it so historical invoices stay reproducible and
 * restatements can be detected by comparing content hashes.
 *
 * @property int $id
 * @property int $cost_provider_id
 * @property string $period
 * @property string|null $source_key
 * @property array<string, mixed> $raw_payload
 * @property string $content_hash
 * @property \Illuminate\Support\Carbon $fetched_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CostProvider $costProvider
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostLineItem> $lineItems
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
        'source_key',
        'raw_payload',
        'content_hash',
        'fetched_at',
    ];

    /**
     * Canonical content hash for a payload. Keys are sorted so an equivalent
     * response with reordered keys hashes identically and is not mistaken for
     * a restatement.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        $canonical = self::sortRecursive($payload);

        return hash('sha256', (string) json_encode($canonical));
    }

    /** @return BelongsTo<CostProvider, $this> */
    public function costProvider(): BelongsTo
    {
        return $this->belongsTo(CostProvider::class);
    }

    /** @return HasMany<CostLineItem, $this> */
    public function lineItems(): HasMany
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

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        if (array_is_list($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }
}
