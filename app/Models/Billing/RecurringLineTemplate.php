<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\Cadence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property string $label
 * @property float $amount
 * @property string $currency
 * @property Cadence $cadence
 * @property \Illuminate\Support\Carbon $active_from
 * @property \Illuminate\Support\Carbon|null $active_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Client|null $client
 * @property-read Project|null $project
 *
 * @method static \Database\Factories\Billing\RecurringLineTemplateFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class RecurringLineTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\RecurringLineTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'project_id',
        'label',
        'amount',
        'currency',
        'cadence',
        'active_from',
        'active_to',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'cadence' => Cadence::class,
            'active_from' => 'date',
            'active_to' => 'date',
        ];
    }
}
