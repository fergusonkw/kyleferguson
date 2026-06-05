<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $cost_provider_id
 * @property int|null $project_id
 * @property string $provider_resource_id
 * @property string $resource_type
 * @property string|null $name
 * @property string|null $provider_project_uuid
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CostProvider $costProvider
 * @property-read Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceAssignment> $assignments
 * @property-read ResourceAssignment|null $currentAssignment
 *
 * @method static \Database\Factories\Billing\ProviderResourceFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class ProviderResource extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\ProviderResourceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'cost_provider_id',
        'project_id',
        'provider_resource_id',
        'resource_type',
        'name',
        'provider_project_uuid',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    /** @return BelongsTo<CostProvider, $this> */
    public function costProvider(): BelongsTo
    {
        return $this->belongsTo(CostProvider::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ResourceAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ResourceAssignment::class);
    }

    /** @return HasOne<ResourceAssignment, $this> */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(ResourceAssignment::class)->whereNull('observed_to');
    }

    public function isAttributed(): bool
    {
        return $this->project_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
