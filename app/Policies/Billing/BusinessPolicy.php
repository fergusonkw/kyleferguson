<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\Business;
use App\Models\User;

final class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, Business $business): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }

    public function update(User $user, Business $business): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }

    public function delete(User $user, Business $business): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }
}
