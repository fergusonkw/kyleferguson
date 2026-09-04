<?php

declare(strict_types=1);

namespace App\Policies\Billing;

use App\Enums\Permission;
use App\Models\Billing\Invoice;
use App\Models\User;

/**
 * Editing a draft and issuing an invoice are deliberately separate
 * permissions: one is routine review, the other puts a document in front of a
 * client and cannot be taken back.
 */
final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value);
    }

    /**
     * Line edits and regeneration — only ever on a draft.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value)
            && $invoice->status->isEditable();
    }

    public function approve(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ApproveInvoices->value);
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ApproveInvoices->value);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ApproveInvoices->value);
    }

    /**
     * Recording money is routine bookkeeping, so it sits with draft editing
     * rather than behind the issuing permission.
     */
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ManageInvoices->value);
    }

    public function downloadPdf(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permission::ViewBilling->value);
    }
}
