<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\Client;
use App\Models\User;

final class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageClients->value);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::ManageClients->value);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::ManageClients->value);
    }
}
