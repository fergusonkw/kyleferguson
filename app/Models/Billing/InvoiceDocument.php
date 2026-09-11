<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice exactly as it was issued: the rendered, self-contained HTML
 * captured at approval or on a resend. Never edited — a later send adds a new
 * row — so any copy a client holds can be reproduced.
 *
 * @property int $id
 * @property int $invoice_id
 * @property InvoiceDocumentReason $reason
 * @property string $html
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Invoice $invoice
 *
 * @method static \Database\Factories\Billing\InvoiceDocumentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class InvoiceDocument extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\InvoiceDocumentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'reason',
        'html',
    ];

    /**
     * A hundred-odd kilobytes of inlined fonts has no place in a JSON response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'html',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => InvoiceDocumentReason::class,
        ];
    }
}
