<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $business_id
 * @property int $client_id
 * @property string $invoice_number
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property InvoiceStatus $status
 * @property string $issue_currency
 * @property float $subtotal
 * @property float $total
 * @property float $fx_rate_snapshot
 * @property string $fx_rate_source
 * @property string $fx_rate_period
 * @property string $template_view_snapshot
 * @property string $email_template_view_snapshot
 * @property string|null $late_fee_terms_snapshot
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property string|null $pdf_path
 * @property string|null $hosted_view_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Business $business
 * @property-read Client $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 *
 * @method static \Database\Factories\Billing\InvoiceFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\InvoiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'client_id',
        'invoice_number',
        'period_start',
        'period_end',
        'status',
        'issue_currency',
        'subtotal',
        'total',
        'fx_rate_snapshot',
        'fx_rate_source',
        'fx_rate_period',
        'template_view_snapshot',
        'email_template_view_snapshot',
        'late_fee_terms_snapshot',
        'approved_at',
        'sent_at',
        'voided_at',
        'pdf_path',
        'hosted_view_token',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('display_order');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->whereNull('deleted_at')->sum('amount');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => InvoiceStatus::class,
            'subtotal' => 'float',
            'total' => 'float',
            'fx_rate_snapshot' => 'float',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
