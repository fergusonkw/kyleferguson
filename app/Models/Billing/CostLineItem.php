<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\CostAttributionState;
use App\Enums\Billing\CostCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One derived cost, normalized to a USD basis, for one provider and period.
 *
 * @property int $id
 * @property int $cost_provider_id
 * @property int|null $provider_resource_id
 * @property int|null $project_id
 * @property int|null $source_payload_id
 * @property string $period
 * @property CostCategory $category
 * @property string $description
 * @property string $source_amount
 * @property string $source_currency
 * @property string $usd_amount
 * @property string $usd_tax
 * @property string|null $source_reference
 * @property array<string, mixed>|null $metadata
 * @property string $line_hash
 * @property \Illuminate\Support\Carbon|null $attributed_at
 * @property \Illuminate\Support\Carbon $derived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CostProvider $costProvider
 * @property-read ProviderResource|null $providerResource
 * @property-read Project|null $project
 * @property-read ProviderBillingPayload|null $sourcePayload
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
        'category',
        'description',
        'source_amount',
        'source_currency',
        'usd_amount',
        'usd_tax',
        'source_reference',
        'metadata',
        'line_hash',
        'attributed_at',
        'derived_at',
    ];

    /**
     * Deterministic idempotency key for a line. Re-deriving the same charge from
     * a re-fetched payload must produce the same hash so the upsert is a no-op;
     * a genuinely different charge must produce a different one.
     *
     * Amounts are deliberately excluded — a restated amount for the same charge
     * updates the existing row rather than creating a duplicate.
     *
     * @param  list<string|null>  $parts
     */
    public static function makeLineHash(array $parts): string
    {
        $canonical = implode('|', array_map(
            static fn (?string $part): string => $part ?? '',
            $parts,
        ));

        return hash('sha256', $canonical);
    }

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

    /**
     * @param  Builder<CostLineItem>  $query
     * @return Builder<CostLineItem>
     */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    /**
     * Limit to lines belonging to a business, through the owning cost provider.
     *
     * @param  Builder<CostLineItem>  $query
     * @return Builder<CostLineItem>
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->whereHas(
            'costProvider',
            fn (Builder $q): Builder => $q->where('business_id', $businessId),
        );
    }

    /**
     * How the hosting line that bills this cost refers back to it.
     *
     * Attribution says whose cost this is; this says whether anyone has
     * actually been charged for it. The two are independent — deleting an
     * invoice does not change which project incurred the cost.
     */
    public function invoiceSourceReference(): ?string
    {
        return $this->project_id === null
            ? null
            : "cost_line_items:period={$this->period};project={$this->project_id}";
    }

    public function attributionState(): CostAttributionState
    {
        if (! $this->category->isAttributable()) {
            return CostAttributionState::Overhead;
        }

        return $this->project_id === null
            ? CostAttributionState::Unattributed
            : CostAttributionState::Attributed;
    }

    /**
     * Total USD cost basis for this line. While the business is unregistered for
     * GST/HST the provider's tax is not recoverable, so it is part of the cost.
     */
    public function usdCostBasis(): string
    {
        return bcadd($this->usd_amount, $this->usd_tax, 4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => CostCategory::class,
            'source_amount' => 'decimal:4',
            'usd_amount' => 'decimal:4',
            'usd_tax' => 'decimal:4',
            'metadata' => 'array',
            'attributed_at' => 'datetime',
            'derived_at' => 'datetime',
        ];
    }
}
