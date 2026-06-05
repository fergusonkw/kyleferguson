<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_super_admin_can_reach_all_admin_pages(): void
    {
        $admin = $this->createSuperAdmin();

        foreach ([
            'admin.home',
            'admin.users.index',
            'admin.settings.index',
            'admin.audit-logs.index',
            'admin.queue-monitor.index',
            'admin.log-viewer.index',
            'admin.maintenance.index',
        ] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_user_without_permission_cannot_view_users(): void
    {
        $user = $this->createUserWithRole(\App\Enums\Role::User->slug());

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_plain_user_can_reach_dashboard_and_settings(): void
    {
        $user = $this->createUserWithRole(\App\Enums\Role::User->slug());

        $this->actingAs($user)->get(route('admin.home'))->assertOk();
        $this->actingAs($user)->get(route('admin.settings.index'))->assertOk();
    }
}
