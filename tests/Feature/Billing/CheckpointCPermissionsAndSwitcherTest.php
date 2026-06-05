<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckpointCPermissionsAndSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_role_grants_all_billing_permissions(): void
    {
        $admin = $this->createAdmin();

        $this->assertTrue($admin->hasPermission(Permission::ViewBilling->value));
        $this->assertTrue($admin->hasPermission(Permission::ManageBusinesses->value));
        $this->assertTrue($admin->hasPermission(Permission::ManageClients->value));
        $this->assertTrue($admin->hasPermission(Permission::ManageProjects->value));
        $this->assertTrue($admin->hasPermission(Permission::ManageCostProviders->value));
    }

    public function test_user_role_is_denied_all_billing_permissions(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());

        $this->assertFalse($user->hasPermission(Permission::ViewBilling->value));
        $this->assertFalse($user->hasPermission(Permission::ManageBusinesses->value));
    }

    public function test_super_admin_bypasses_all_billing_policies(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();

        $this->actingAs($superAdmin);

        $this->assertTrue($superAdmin->can('viewAny', Business::class));
        $this->assertTrue($superAdmin->can('update', $business));
        $this->assertTrue($superAdmin->can('delete', $client));
        $this->assertTrue($superAdmin->can('update', $project));
        $this->assertTrue($superAdmin->can('rotateToken', $provider));
    }

    public function test_admin_can_view_but_must_have_specific_perm_to_manage_business(): void
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['slug' => 'billing-viewer'],
            ['name' => 'Billing Viewer', 'level' => 5],
        );
        $viewPerm = \App\Models\Permission::query()->where('slug', Permission::ViewBilling->value)->firstOrFail();
        $role->permissions()->sync([$viewPerm->id]);
        $user->roles()->attach($role->id);

        $business = Business::factory()->create();
        $this->actingAs($user);

        $this->assertTrue($user->can('viewAny', Business::class));
        $this->assertFalse($user->can('update', $business));
        $this->assertFalse($user->can('delete', $business));
    }

    public function test_current_business_defaults_to_first_when_none_set(): void
    {
        $first = Business::factory()->create(['name' => 'Alpha']);
        Business::factory()->create(['name' => 'Beta']);

        $this->actingAs($this->createSuperAdmin());

        $resolved = app(CurrentBusiness::class)->get();
        $this->assertNotNull($resolved);
        $this->assertSame($first->id, $resolved->id);
    }

    public function test_current_business_persists_in_session(): void
    {
        $alpha = Business::factory()->create(['name' => 'Alpha']);
        $beta = Business::factory()->create(['name' => 'Beta']);

        $this->actingAs($this->createSuperAdmin());

        $service = app(CurrentBusiness::class);
        $service->set($beta);

        $this->assertSame($beta->id, $service->get()->id);
        $this->assertNotSame($alpha->id, $service->get()->id);
    }

    public function test_billing_dashboard_requires_view_billing_permission(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());

        $this->actingAs($user)
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    }

    public function test_billing_dashboard_loads_for_admin(): void
    {
        $admin = $this->createAdmin();
        $business = Business::factory()->create(['name' => 'Alpha']);

        $this->actingAs($admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Alpha');
    }

    public function test_business_switcher_updates_session(): void
    {
        $admin = $this->createAdmin();
        $alpha = Business::factory()->create(['name' => 'Alpha']);
        $beta = Business::factory()->create(['name' => 'Beta']);

        $this->actingAs($admin)
            ->post(route('admin.billing.switch', $beta))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Beta');
    }

    public function test_billing_routes_require_authentication(): void
    {
        $this->get(route('admin.billing.index'))->assertRedirect(route('login'));
    }
}
