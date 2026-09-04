<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\RecurringCadence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fixed line that attaches itself to drafts — a Forge subscription, a domain
 * renewal, a retainer. Scoped to a client, or narrowed to one of their projects.
 *
 * @property int $id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property string $label
 * @property string $amount
 * @property string $currency
 * @property RecurringCadence $cadence
 * @property Carbon $active_from
 * @property Carbon|null $active_to
 * @property string|null $notes
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
        'notes',
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
     * Templates whose active window overlaps the period.
     *
     * @param  Builder<RecurringLineTemplate>  $query
     * @return Builder<RecurringLineTemplate>
     */
    public function scopeActiveDuring(Builder $query, Carbon $periodStart, Carbon $periodEnd): Builder
    {
        return $query
            ->whereDate('active_from', '<=', $periodEnd)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('active_to')
                ->orWhereDate('active_to', '>=', $periodStart));
    }

    /**
     * Templates belonging to a client, whether attached directly or through
     * one of that client's projects.
     *
     * @param  Builder<RecurringLineTemplate>  $query
     * @return Builder<RecurringLineTemplate>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('client_id', $clientId)
            ->orWhereHas('project', fn (Builder $p): Builder => $p->where('client_id', $clientId)));
    }

    /**
     * Whether this template contributes a line for the period opening on
     * `$periodStart` — the window must cover it and the cadence must land on it.
     */
    public function billsInPeriod(Carbon $periodStart, Carbon $periodEnd): bool
    {
        if ($this->active_from->greaterThan($periodEnd)) {
            return false;
        }

        if ($this->active_to !== null && $this->active_to->lessThan($periodStart)) {
            return false;
        }

        return $this->cadence->billsIn($this->active_from, $periodStart);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cadence' => RecurringCadence::class,
            'active_from' => 'date',
            'active_to' => 'date',
        ];
    }
}
