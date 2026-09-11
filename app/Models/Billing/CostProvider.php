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
 * @property int|null $client_id
 * @property CostProviderSlug $slug
 * @property string $display_name
 * @property string|null $invoice_label
 * @property string|null $invoice_description
 * @property array<string, mixed> $credentials
 * @property array<string, mixed>|null $config
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property SyncStatus $last_sync_status
 * @property string|null $last_sync_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Business $business
 * @property-read Client|null $client
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
        'client_id',
        'slug',
        'display_name',
        'invoice_label',
        'invoice_description',
        'credentials',
        'config',
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

    /**
     * The client this provider account belongs to, for account-per-client
     * providers (SMTP2GO). Null for account-per-business providers.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /**
     * Read a provider-specific setting from the non-encrypted `config` blob.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * What this provider's costs are called on a client invoice.
     *
     * Deliberately not `display_name` — that is the operator's name for the
     * account ("SMTP2Go — Acme"), which is internal bookkeeping. The client
     * reads the service they received.
     */
    public function invoiceLabel(): string
    {
        return filled($this->invoice_label)
            ? $this->invoice_label
            : $this->slug->defaultInvoiceLabel();
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
            'config' => 'array',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_status' => SyncStatus::class,
        ];
    }
}
