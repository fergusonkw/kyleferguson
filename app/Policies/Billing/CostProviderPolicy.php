<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\CostProvider;
use App\Models\User;

final class CostProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, CostProvider $costProvider): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCostProviders->value);
    }

    public function update(User $user, CostProvider $costProvider): bool
    {
        return $user->hasPermission(Permission::ManageCostProviders->value);
    }

    public function delete(User $user, CostProvider $costProvider): bool
    {
        return $user->hasPermission(Permission::ManageCostProviders->value);
    }

    public function rotateToken(User $user, CostProvider $costProvider): bool
    {
        return $user->hasPermission(Permission::ManageCostProviders->value);
    }

    public function sync(User $user, CostProvider $costProvider): bool
    {
        return $user->hasPermission(Permission::ManageCostProviders->value);
    }
}
