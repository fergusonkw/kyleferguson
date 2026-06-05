<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role as RoleEnum;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $theme_preference
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_disabled
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property bool $is_locked
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property int|null $locked_by
 * @property string|null $remember_token
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 * @property-read UserPreference|null $preference
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'theme_preference',
        'is_disabled',
        'disabled_at',
        'disabled_by',
        'is_locked',
        'locked_at',
        'locked_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The roles assigned to this user.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * The user's preferences.
     *
     * @return HasOne<UserPreference, $this>
     */
    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * Whether the user has any of the given role slugs.
     */
    public function hasRole(string|array $roles): bool
    {
        return $this->roles()->whereIn('slug', (array) $roles)->exists();
    }

    /**
     * Whether the user has all of the given role slugs.
     *
     * @param  array<int, string>  $roles
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->roles()->whereIn('slug', $roles)->count() === count(array_unique($roles));
    }

    /**
     * Whether the user has a permission via any of their roles.
     * Super admins implicitly have every permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('slug', $permissionSlug))
            ->exists();
    }

    /**
     * All permission slugs granted to this user across their roles.
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        return Permission::query()
            ->whereHas('roles.users', fn ($query) => $query->whereKey($this->getKey()))
            ->pluck('slug')
            ->all();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()->where('slug', RoleEnum::SuperAdmin->slug())->exists();
    }

    public function isAdmin(): bool
    {
        return $this->roles()->whereIn('slug', RoleEnum::adminRoles())->exists();
    }

    /**
     * The highest role level held by this user (0 if none).
     */
    public function getMaxRoleLevel(): int
    {
        return (int) $this->roles()->max('level');
    }

    public function assignRole(Role $role): void
    {
        $this->roles()->syncWithoutDetaching([$role->getKey()]);
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(array $roleIds): void
    {
        $this->roles()->sync($roleIds);
    }

    /**
     * Whether two-factor enrollment is mandatory for this application.
     */
    public function requiresTwoFactor(): bool
    {
        return (bool) config('auth.two_factor.required', false);
    }

    public function disable(self $disabledBy): void
    {
        $this->update([
            'is_disabled' => true,
            'disabled_at' => now(),
            'disabled_by' => $disabledBy->id,
        ]);

        $this->tokens()->delete();
    }

    public function enable(): void
    {
        $this->update([
            'is_disabled' => false,
            'disabled_at' => null,
            'disabled_by' => null,
        ]);
    }

    public function lock(self $lockedBy): void
    {
        $this->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $lockedBy->id,
        ]);

        $this->tokens()->delete();
    }

    public function unlock(): void
    {
        $this->update([
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    public function isActive(): bool
    {
        return ! $this->is_disabled && ! $this->is_locked;
    }

    /** @return BelongsTo<User, $this> */
    public function disabledByUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'disabled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'locked_by');
    }

    /**
     * Two-factor is only "enabled" once the user has confirmed a code during
     * enrollment — a secret with no confirmation is a half-finished setup.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Unused recovery codes, decrypted.
     *
     * @return list<string>
     */
    public function twoFactorRecoveryCodes(): array
    {
        $codes = $this->two_factor_recovery_codes;

        return is_array($codes) ? array_values($codes) : [];
    }

    /**
     * Consume a single-use recovery code. Returns false if it is not valid.
     */
    public function useTwoFactorRecoveryCode(string $code): bool
    {
        $code = trim($code);
        $codes = $this->twoFactorRecoveryCodes();

        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $this->forceFill([
            'two_factor_recovery_codes' => array_values($codes),
        ])->save();

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_disabled' => 'boolean',
            'disabled_at' => 'datetime',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
