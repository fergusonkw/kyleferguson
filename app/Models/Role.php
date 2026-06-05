<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name Human-readable role name (e.g., Administrator)
 * @property string $slug URL-friendly role identifier
 * @property string|null $description Detailed description of role capabilities
 * @property int $level Hierarchy level: higher = more privilege
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 *
 * @mixin \Eloquent
 */
final class Role extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'level',
    ];

    /**
     * Whether the slug belongs to a built-in role defined by the Role enum.
     * Core roles cannot be deleted from the management UI.
     */
    public static function isCore(string $slug): bool
    {
        return in_array($slug, array_map(fn (RoleEnum $role): string => $role->slug(), RoleEnum::cases()), true);
    }

    /**
     * The permissions that belong to this role.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * The users that have this role.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withTimestamps();
    }

    /**
     * Check if this role has a specific permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions()->where('slug', $permissionSlug)->exists();
    }

    /**
     * Sync permissions for this role.
     *
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(array $permissionIds): void
    {
        $this->permissions()->sync($permissionIds);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }
}
