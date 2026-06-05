<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_resource_id
 * @property int|null $project_id
 * @property string|null $provider_project_uuid
 * @property \Illuminate\Support\Carbon $observed_from
 * @property \Illuminate\Support\Carbon|null $observed_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ProviderResource $providerResource
 * @property-read Project|null $project
 *
 * @method static \Database\Factories\Billing\ResourceAssignmentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class ResourceAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\ResourceAssignmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider_resource_id',
        'project_id',
        'provider_project_uuid',
        'observed_from',
        'observed_to',
    ];

    /** @return BelongsTo<ProviderResource, $this> */
    public function providerResource(): BelongsTo
    {
        return $this->belongsTo(ProviderResource::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isCurrent(): bool
    {
        return $this->observed_to === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_from' => 'datetime',
            'observed_to' => 'datetime',
        ];
    }
}
