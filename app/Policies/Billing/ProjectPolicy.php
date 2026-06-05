<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\Project;
use App\Models\User;

final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageProjects->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects->value);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects->value);
    }
}
