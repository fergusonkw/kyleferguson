<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name Human-readable permission name
 * @property string $slug Permission identifier (e.g., users.create)
 * @property string|null $description What this permission allows
 * @property string|null $resource_type Resource category (e.g., User Management)
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 *
 * @mixin \Eloquent
 */
final class Permission extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'resource_type',
    ];

    /**
     * The roles that have this permission.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * Scope to filter permissions by resource type.
     */
    public function scopeForResource(\Illuminate\Database\Eloquent\Builder $query, string $resourceType): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('resource_type', $resourceType);
    }
}
