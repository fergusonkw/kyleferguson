<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\FxRateSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $currency_from
 * @property string $currency_to
 * @property string $period
 * @property float $rate
 * @property FxRateSource $source
 * @property \Illuminate\Support\Carbon $fetched_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Database\Factories\Billing\FxRateFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class FxRate extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\FxRateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'currency_from',
        'currency_to',
        'period',
        'rate',
        'source',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'source' => FxRateSource::class,
            'fetched_at' => 'datetime',
        ];
    }
}
