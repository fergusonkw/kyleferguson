<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $business_id
 * @property CostProviderSlug $slug
 * @property string $display_name
 * @property array<string, mixed> $credentials
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property SyncStatus $last_sync_status
 * @property string|null $last_sync_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Business $business
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProviderResource> $resources
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProviderBillingPayload> $billingPayloads
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostLineItem> $costLineItems
 *
 * @method static \Database\Factories\Billing\CostProviderFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class CostProvider extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\CostProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'slug',
        'display_name',
        'credentials',
        'enabled',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    /** @var list<string> */
    protected $hidden = [
        'credentials',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<ProviderResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ProviderResource::class);
    }

    /** @return HasMany<ProviderBillingPayload, $this> */
    public function billingPayloads(): HasMany
    {
        return $this->hasMany(ProviderBillingPayload::class);
    }

    /** @return HasMany<CostLineItem, $this> */
    public function costLineItems(): HasMany
    {
        return $this->hasMany(CostLineItem::class);
    }

    public function markSyncRunning(): void
    {
        $this->update([
            'last_sync_status' => SyncStatus::Running,
            'last_sync_error' => null,
        ]);
    }

    public function markSyncSucceeded(): void
    {
        $this->update([
            'last_synced_at' => now(),
            'last_sync_status' => SyncStatus::Success,
            'last_sync_error' => null,
        ]);
    }

    public function markSyncFailed(string $error): void
    {
        $this->update([
            'last_sync_status' => SyncStatus::Failed,
            'last_sync_error' => $error,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => CostProviderSlug::class,
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_status' => SyncStatus::class,
        ];
    }
}
