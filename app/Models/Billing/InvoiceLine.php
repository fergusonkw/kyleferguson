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
 * @property InvoiceLineType $line_type
 * @property float $amount
 * @property string|null $source_reference
 * @property int $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Invoice $invoice
 * @property-read InvoiceLine|null $parent
 * @property-read Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $children
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
        'line_type',
        'amount',
        'source_reference',
        'display_order',
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_type' => InvoiceLineType::class,
            'amount' => 'float',
        ];
    }
}
