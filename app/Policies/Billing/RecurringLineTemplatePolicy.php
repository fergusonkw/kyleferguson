<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\RecurringLineTemplate;
use App\Models\User;

/**
 * A recurring template is a standing instruction to bill a client every
 * period, so it sits with invoice management rather than client management —
 * changing one changes what future invoices charge.
 */
final class RecurringLineTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, RecurringLineTemplate $template): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value);
    }

    public function update(User $user, RecurringLineTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value);
    }

    public function delete(User $user, RecurringLineTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value);
    }
}
