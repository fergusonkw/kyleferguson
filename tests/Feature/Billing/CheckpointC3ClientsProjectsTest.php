<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckpointC3ClientsProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_client(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), [
                'business_id' => $business->id,
                'name' => 'Acme Corp',
                'contact_email' => 'a@acme.test',
                'billing_currency' => 'CAD',
                'status' => 'active',
                'default_markup_type' => 'percent',
                'default_markup_value' => 20,
            ])
            ->assertOk();

        $this->assertDatabaseHas('clients', ['business_id' => $business->id, 'name' => 'Acme Corp']);
    }

    public function test_client_currency_must_match_business_supported_currencies(): void
    {
        $business = Business::factory()->create(['supported_currencies' => ['CAD']]);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), [
                'business_id' => $business->id,
                'name' => 'Acme',
                'contact_email' => 'a@acme.test',
                'billing_currency' => 'USD',
                'status' => 'active',
                'default_markup_type' => 'passthrough',
                'default_markup_value' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_currency']);
    }

    public function test_user_role_cannot_create_client(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());
        $business = Business::factory()->create();

        $this->actingAs($user)
            ->postJson(route('admin.billing.clients.store'), [
                'business_id' => $business->id,
                'name' => 'X',
                'contact_email' => 'x@x.com',
                'billing_currency' => 'CAD',
                'status' => 'active',
                'default_markup_type' => 'passthrough',
                'default_markup_value' => 0,
            ])
            ->assertForbidden();
    }

    public function test_client_cannot_be_deleted_with_attached_projects(): void
    {
        $client = Client::factory()->create();
        Project::factory()->for($client)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.clients.destroy', $client))
            ->assertStatus(422);

        $this->assertModelExists($client);
    }

    public function test_clients_data_endpoint_scopes_to_current_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        Client::factory()->for($business)->create();
        Client::factory()->count(2)->for($other)->create();

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.clients.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);
    }

    public function test_admin_can_create_project(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Marketing Site',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertDatabaseHas('projects', ['client_id' => $client->id, 'name' => 'Marketing Site']);
    }

    public function test_project_with_do_uuid_must_be_unique(): void
    {
        $client = Client::factory()->create();
        $uuid = '00000000-0000-0000-0000-000000000001';
        Project::factory()->for($client)->linkedToDigitalOcean($uuid)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Other',
                'status' => 'active',
                'do_project_uuid' => $uuid,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['do_project_uuid']);
    }

    public function test_project_markup_value_required_when_type_supplied(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'X',
                'status' => 'active',
                'markup_type' => 'percent',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['markup_value']);
    }

    public function test_projects_data_scopes_to_current_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $clientA = Client::factory()->for($business)->create();
        $clientB = Client::factory()->for($other)->create();
        Project::factory()->count(2)->for($clientA)->create();
        Project::factory()->for($clientB)->create();

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.projects.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2);
    }

    public function test_available_do_projects_returns_synced_uuids_for_current_business(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->create();
        ProviderResource::factory()->for($provider, 'costProvider')->create([
            'provider_project_uuid' => '00000000-0000-0000-0000-000000000abc',
            'metadata' => ['do_project_name' => 'Acme DO', 'do_project_is_default' => false],
        ]);

        // A resource on a different business should not leak in.
        $other = CostProvider::factory()->create();
        ProviderResource::factory()->for($other, 'costProvider')->create([
            'provider_project_uuid' => '00000000-0000-0000-0000-000000000xyz',
            'metadata' => ['do_project_name' => 'Other DO'],
        ]);

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.billing.projects.available-do-projects'))
            ->assertOk();

        $uuids = collect($response->json('data'))->pluck('uuid')->all();
        $this->assertContains('00000000-0000-0000-0000-000000000abc', $uuids);
        $this->assertNotContains('00000000-0000-0000-0000-000000000xyz', $uuids);
    }

    public function test_unattributed_resources_view_returns_only_unmapped_resources(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->linkedToDigitalOcean()->create();

        ProviderResource::factory()->for($provider, 'costProvider')->create(['project_id' => null]);
        ProviderResource::factory()->for($provider, 'costProvider')->create(['project_id' => null]);
        ProviderResource::factory()->for($provider, 'costProvider')->create(['project_id' => $project->id]);

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.projects.unattributed', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2);
    }

    public function test_available_clients_returns_only_current_business_clients(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        Client::factory()->for($business)->create(['name' => 'In Scope']);
        Client::factory()->for($other)->create(['name' => 'Out of Scope']);

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.billing.projects.available-clients'))
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('In Scope', $names);
        $this->assertNotContains('Out of Scope', $names);
    }
}
