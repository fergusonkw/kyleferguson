<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\InvoiceLineType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int|null $parent_id
 * @property int|null $project_id
 * @property string $label
 * @property string|null $description
 * @property string|null $quantity
 * @property string|null $unit
 * @property string|null $unit_rate
 * @property InvoiceLineType $line_type
 * @property string $amount
 * @property string|null $source_amount
 * @property string|null $source_currency
 * @property string|null $fx_rate_applied
 * @property string|null $cost_basis_usd
 * @property string|null $source_reference
 * @property bool $is_display_only
 * @property int $display_order
 * @property array<string, mixed>|null $metadata
 * @property-read Invoice $invoice
 * @property-read InvoiceLine|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $children
 * @property-read Project|null $project
 *
 * @method static \Database\Factories\Billing\InvoiceLineFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class InvoiceLine extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\InvoiceLineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'parent_id',
        'project_id',
        'label',
        'description',
        'quantity',
        'unit',
        'unit_rate',
        'line_type',
        'amount',
        'source_amount',
        'source_currency',
        'fx_rate_applied',
        'cost_basis_usd',
        'source_reference',
        'is_display_only',
        'display_order',
        'metadata',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Sub-items are shown for transparency but their amounts are already
     * inside the parent's total, so only non-display lines are summed.
     */
    public function countsTowardTotal(): bool
    {
        return ! $this->is_display_only;
    }

    /**
     * Whether this line is priced by quantity rather than as a flat figure.
     */
    public function isMetered(): bool
    {
        return $this->quantity !== null && $this->unit_rate !== null;
    }

    /**
     * The quantity as a client reads it — "12 hrs", or just "12" with no unit.
     * Trailing zeros go, so 12.00 reads as 12 while 1.50 keeps its half.
     */
    public function quantityLabel(): ?string
    {
        if ($this->quantity === null) {
            return null;
        }

        $number = rtrim(rtrim((string) $this->quantity, '0'), '.');

        return filled($this->unit) ? $number.' '.$this->unit : $number;
    }

    /**
     * How the line total was arrived at — "12 hrs × $95.00". A figure a client
     * can check is a figure they are less likely to query.
     */
    public function rateNote(): ?string
    {
        return $this->isMetered()
            ? sprintf('%s × $%s', $this->quantityLabel(), number_format((float) $this->unit_rate, 2))
            : null;
    }

    /**
     * Whether this line was incurred in a currency other than the one the
     * invoice is issued in, and so carries a conversion worth showing.
     */
    public function wasConverted(): bool
    {
        return $this->source_currency !== null
            && $this->source_currency !== $this->invoice->issue_currency;
    }

    /**
     * How the converted amount was arrived at, for the client to read —
     * "USD 18.00 at 1.3750".
     */
    public function conversionNote(): ?string
    {
        if (! $this->wasConverted()) {
            return null;
        }

        return sprintf(
            '%s %s at %s',
            $this->source_currency,
            number_format((float) $this->source_amount, 2),
            rtrim(rtrim((string) $this->fx_rate_applied, '0'), '.'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_type' => InvoiceLineType::class,
            'amount' => 'decimal:2',
            'quantity' => 'decimal:2',
            'unit_rate' => 'decimal:2',
            'source_amount' => 'decimal:2',
            'fx_rate_applied' => 'decimal:8',
            'cost_basis_usd' => 'decimal:4',
            'is_display_only' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
