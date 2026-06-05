<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_assigned_role_and_not_others(): void
    {
        $admin = $this->createAdmin();

        $this->assertTrue($admin->hasRole(RoleEnum::Admin->slug()));
        $this->assertFalse($admin->hasRole(RoleEnum::User->slug()));
        $this->assertTrue($admin->hasRole([RoleEnum::Admin->slug(), RoleEnum::User->slug()]));
    }

    public function test_admin_has_seeded_permissions_but_not_role_management(): void
    {
        $admin = $this->createAdmin();

        $this->assertTrue($admin->hasPermission(Permission::ViewUsers->value));
        $this->assertFalse($admin->hasPermission(Permission::ManageRoles->value));
        $this->assertFalse($admin->isSuperAdmin());
    }

    public function test_super_admin_bypasses_every_permission(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->hasPermission(Permission::ManageRoles->value));
        $this->assertTrue($superAdmin->hasPermission('a.permission.that.does.not.exist'));
        $this->assertSame(RoleEnum::SuperAdmin->level(), $superAdmin->getMaxRoleLevel());
    }
}
