<?php

declare(strict_types=1);

namespace App\Models\Billing;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $legal_entity_id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $address
 * @property string $contact_email
 * @property string|null $cheque_payable_to
 * @property int|null $logo_id
 * @property string|null $brand_primary_color
 * @property string|null $brand_secondary_color
 * @property string $invoice_template_view
 * @property string $email_template_view
 * @property string $invoice_number_prefix
 * @property int $invoice_number_sequence
 * @property string $default_currency
 * @property array<int, string>|null $supported_currencies
 * @property string $fx_source
 * @property string $notification_email
 * @property string $daily_reminder_time
 * @property string|null $late_fee_terms
 * @property int $payment_terms_days
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LegalEntity|null $legalEntity
 * @property-read BusinessLogo|null $logo
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Client> $clients
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostProvider> $costProviders
 *
 * @method static \Database\Factories\Billing\BusinessFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class Business extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\BusinessFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'legal_entity_id',
        'name',
        'legal_name',
        'address',
        'contact_email',
        'cheque_payable_to',
        'logo_id',
        'brand_primary_color',
        'brand_secondary_color',
        'invoice_template_view',
        'email_template_view',
        'invoice_number_prefix',
        'invoice_number_sequence',
        'default_currency',
        'supported_currencies',
        'fx_source',
        'notification_email',
        'daily_reminder_time',
        'late_fee_terms',
        'payment_terms_days',
    ];

    /**
     * The person or corporation this business trades under. Registration and
     * the small-supplier threshold are theirs, not the trade name's.
     *
     * @return BelongsTo<LegalEntity, $this>
     */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /**
     * The current logo. Replacing it points here at a new row; the old one
     * stays, for the invoices that were issued with it.
     *
     * @return BelongsTo<BusinessLogo, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(BusinessLogo::class, 'logo_id');
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<CostProvider, $this> */
    public function costProviders(): HasMany
    {
        return $this->hasMany(CostProvider::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Whether the business's legal entity is GST/HST registered as of $on.
     */
    public function isTaxRegisteredOn(DateTimeInterface $on): bool
    {
        return $this->legalEntity?->isTaxRegisteredOn($on) ?? false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supported_currencies' => 'array',
            'invoice_number_sequence' => 'integer',
        ];
    }
}
