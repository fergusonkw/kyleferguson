<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\MarkupType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string|null $contact_name
 * @property string $contact_email
 * @property string|null $billing_address
 * @property string $billing_currency
 * @property ClientStatus $status
 * @property MarkupType $default_markup_type
 * @property string $default_markup_value
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Business $business
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Project> $projects
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Invoice> $invoices
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecurringLineTemplate> $recurringLineTemplates
 *
 * @method static \Database\Factories\Billing\ClientFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class Client extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\ClientFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'name',
        'contact_name',
        'contact_email',
        'billing_address',
        'billing_currency',
        'status',
        'default_markup_type',
        'default_markup_value',
        'notes',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<RecurringLineTemplate, $this> */
    public function recurringLineTemplates(): HasMany
    {
        return $this->hasMany(RecurringLineTemplate::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'default_markup_type' => MarkupType::class,
            'default_markup_value' => 'decimal:4',
        ];
    }
}
