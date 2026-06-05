<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewRoles->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission(Permission::ViewRoles->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageRoles->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission(Permission::ManageRoles->value);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission(Permission::ManageRoles->value) && ! Role::isCore($role->slug);
    }
}
