<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles, permissions, and their assignments.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\PermissionSeeder::class,
            \Database\Seeders\RolePermissionSeeder::class,
        ]);
    }

    /**
     * Create a user assigned the given role slug (seeds roles/permissions first).
     */
    protected function createUserWithRole(string $roleSlug): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    protected function createSuperAdmin(): User
    {
        return $this->createUserWithRole(RoleEnum::SuperAdmin->slug());
    }

    protected function createAdmin(): User
    {
        return $this->createUserWithRole(RoleEnum::Admin->slug());
    }
}
