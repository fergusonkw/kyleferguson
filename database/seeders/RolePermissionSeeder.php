<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIdsBySlug = Permission::query()->pluck('id', 'slug');

        // Super admins bypass gates entirely (see AppServiceProvider), but we
        // still grant every permission so the relationship reflects reality.
        $map = [
            RoleEnum::SuperAdmin->slug() => PermissionEnum::slugs(),
            RoleEnum::Admin->slug() => [
                PermissionEnum::ViewUsers->value,
                PermissionEnum::CreateUsers->value,
                PermissionEnum::UpdateUsers->value,
                PermissionEnum::DeleteUsers->value,
                PermissionEnum::ViewRoles->value,
                PermissionEnum::ViewAuditLogs->value,
                PermissionEnum::ViewLogViewer->value,
                PermissionEnum::ViewQueueMonitor->value,
                PermissionEnum::ManageMaintenance->value,
                PermissionEnum::ViewBilling->value,
                PermissionEnum::ManageBusinesses->value,
                PermissionEnum::ManageClients->value,
                PermissionEnum::ManageProjects->value,
                PermissionEnum::ManageCostProviders->value,
                PermissionEnum::ManageInvoices->value,
                PermissionEnum::ApproveInvoices->value,
            ],
            RoleEnum::User->slug() => [],
        ];

        foreach ($map as $roleSlug => $permissionSlugs) {
            $role = Role::where('slug', $roleSlug)->first();

            if ($role === null) {
                continue;
            }

            $ids = collect($permissionSlugs)
                ->map(fn (string $slug) => $permissionIdsBySlug[$slug] ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }
    }
}
