<?php

declare(strict_types=1);

namespace App\Models\Billing;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $address
 * @property string $contact_email
 * @property string|null $cheque_payable_to
 * @property string|null $logo_path
 * @property string|null $brand_primary_color
 * @property string|null $brand_secondary_color
 * @property string $invoice_template_view
 * @property string $email_template_view
 * @property string $invoice_number_prefix
 * @property int $invoice_number_sequence
 * @property string $default_currency
 * @property array<int, string>|null $supported_currencies
 * @property string $fx_source
 * @property \Illuminate\Support\Carbon|null $tax_registered_from
 * @property string $notification_email
 * @property string $daily_reminder_time
 * @property string|null $late_fee_terms
 * @property int $payment_terms_days
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
        'name',
        'legal_name',
        'address',
        'contact_email',
        'cheque_payable_to',
        'logo_path',
        'brand_primary_color',
        'brand_secondary_color',
        'invoice_template_view',
        'email_template_view',
        'invoice_number_prefix',
        'invoice_number_sequence',
        'default_currency',
        'supported_currencies',
        'fx_source',
        'tax_registered_from',
        'notification_email',
        'daily_reminder_time',
        'late_fee_terms',
        'payment_terms_days',
    ];

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
     * Whether the business is currently GST/HST registered as of $on.
     */
    public function isTaxRegisteredOn(DateTimeInterface $on): bool
    {
        if ($this->tax_registered_from === null) {
            return false;
        }

        return $this->tax_registered_from->lessThanOrEqualTo($on);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supported_currencies' => 'array',
            'tax_registered_from' => 'date',
            'invoice_number_sequence' => 'integer',
        ];
    }
}
