<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewUsers->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasPermission(Permission::ViewUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CreateUsers->value);
    }

    public function update(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->hasPermission(Permission::UpdateUsers->value) && ! $model->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermission(Permission::DeleteUsers->value) && ! $model->isSuperAdmin();
    }

    public function disable(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermission(Permission::UpdateUsers->value) && ! $model->isSuperAdmin();
    }

    public function lock(User $user, User $model): bool
    {
        return $this->disable($user, $model);
    }

    /**
     * Whether the user may assign/sync roles to other users.
     */
    public function assignRoles(User $user): bool
    {
        return $user->hasPermission(Permission::ManageRoles->value);
    }
}
