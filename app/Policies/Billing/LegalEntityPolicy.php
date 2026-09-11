<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\LegalEntity;
use App\Models\User;

/**
 * Legal entities are business configuration, so they share the businesses
 * permission rather than adding a separate one to grant.
 */
final class LegalEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, LegalEntity $legalEntity): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }

    public function update(User $user, LegalEntity $legalEntity): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }

    public function delete(User $user, LegalEntity $legalEntity): bool
    {
        return $user->hasPermission(Permission::ManageBusinesses->value);
    }
}
