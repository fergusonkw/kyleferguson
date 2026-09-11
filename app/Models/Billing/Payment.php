<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $amount
 * @property \Illuminate\Support\Carbon $received_at
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $recorded_by_user_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Invoice $invoice
 * @property-read User|null $recordedBy
 *
 * @method static \Database\Factories\Billing\PaymentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\PaymentFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'amount',
        'received_at',
        'method',
        'reference',
        'notes',
        'recorded_by_user_id',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isVoided(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
            'method' => PaymentMethod::class,
        ];
    }
}
