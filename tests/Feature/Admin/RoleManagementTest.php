<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_roles_index(): void
    {
        $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.roles.index'))
            ->assertOk();
    }

    public function test_admin_can_view_but_cannot_create_roles(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk();

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Editor',
            'level' => 20,
        ])->assertForbidden();
    }

    public function test_super_admin_can_create_role_with_permissions(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $permissionId = PermissionModel::where('slug', Permission::ViewUsers->value)->value('id');

        $this->actingAs($superAdmin)->postJson(route('admin.roles.store'), [
            'name' => 'Content Editor',
            'description' => 'Can manage users',
            'level' => 20,
            'permissions' => [$permissionId],
        ])->assertOk()->assertJson(['success' => true]);

        $role = Role::where('slug', 'content-editor')->first();
        $this->assertNotNull($role);
        $this->assertSame(20, $role->level);
        $this->assertTrue($role->permissions()->where('slug', Permission::ViewUsers->value)->exists());
    }

    public function test_super_admin_can_update_role_permissions(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $role = Role::where('slug', RoleEnum::User->slug())->firstOrFail();
        $permissionId = PermissionModel::where('slug', Permission::ViewAuditLogs->value)->value('id');

        $this->actingAs($superAdmin)->putJson(route('admin.roles.update', $role), [
            'name' => $role->name,
            'level' => $role->level,
            'permissions' => [$permissionId],
        ])->assertOk();

        $this->assertTrue($role->fresh()->permissions()->where('slug', Permission::ViewAuditLogs->value)->exists());
    }

    public function test_core_roles_cannot_be_deleted(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $core = Role::where('slug', RoleEnum::Admin->slug())->firstOrFail();

        $this->actingAs($superAdmin)->deleteJson(route('admin.roles.destroy', $core))
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['slug' => RoleEnum::Admin->slug()]);
    }

    public function test_custom_role_without_users_can_be_deleted(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $role = Role::create(['name' => 'Temp', 'slug' => 'temp', 'description' => null, 'level' => 5]);

        $this->actingAs($superAdmin)->deleteJson(route('admin.roles.destroy', $role))
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['slug' => 'temp']);
    }
}
