<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $business_id
 * @property int $client_id
 * @property string $invoice_number
 * @property string $period
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property \Illuminate\Support\Carbon|null $issued_on
 * @property \Illuminate\Support\Carbon|null $due_on
 * @property InvoiceStatus $status
 * @property string $issue_currency
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $fx_rate_snapshot
 * @property string|null $fx_rate_source
 * @property string|null $fx_rate_period
 * @property string $template_view_snapshot
 * @property string $email_template_view_snapshot
 * @property string|null $late_fee_terms_snapshot
 * @property array<string, mixed>|null $business_snapshot
 * @property array<string, mixed>|null $client_snapshot
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property string|null $pdf_path
 * @property string $hosted_view_token
 * @property string|null $notes
 * @property-read Business $business
 * @property-read Client $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $topLevelLines
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
        'period',
        'period_start',
        'period_end',
        'issued_on',
        'due_on',
        'status',
        'issue_currency',
        'subtotal',
        'tax_total',
        'total',
        'fx_rate_snapshot',
        'fx_rate_source',
        'fx_rate_period',
        'template_view_snapshot',
        'email_template_view_snapshot',
        'late_fee_terms_snapshot',
        'business_snapshot',
        'client_snapshot',
        'approved_at',
        'sent_at',
        'voided_at',
        'pdf_path',
        'hosted_view_token',
        'notes',
    ];

    public static function generateHostedViewToken(): string
    {
        return Str::random(64);
    }

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

    /**
     * Parent lines only — sub-items are reached through their parent.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function topLevelLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)
            ->whereNull('parent_id')
            ->orderBy('display_order');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [InvoiceStatus::Paid, InvoiceStatus::Void]);
    }

    /**
     * Sum of live (non-voided) payments.
     */
    public function amountPaid(): string
    {
        return number_format((float) $this->payments()->sum('amount'), 2, '.', '');
    }

    public function balanceDue(): string
    {
        return bcsub($this->total, $this->amountPaid(), 2);
    }

    public function isOverpaid(): bool
    {
        return bccomp($this->amountPaid(), $this->total, 2) === 1;
    }

    public function overpaymentAmount(): string
    {
        return $this->isOverpaid()
            ? bcsub($this->amountPaid(), $this->total, 2)
            : '0.00';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_on' => 'date',
            'due_on' => 'date',
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'fx_rate_snapshot' => 'decimal:8',
            'business_snapshot' => 'array',
            'client_snapshot' => 'array',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
