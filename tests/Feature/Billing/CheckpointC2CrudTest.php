<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\SyncStatus;
use App\Enums\Role as RoleEnum;
use App\Jobs\Billing\SyncDigitalOceanProjectsJob;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CheckpointC2CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_business(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.businesses.store'), $this->adminPayload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('businesses', ['name' => 'Acme Hosting', 'default_currency' => 'CAD']);
    }

    public function test_business_name_must_be_unique(): void
    {
        Business::factory()->create(['name' => 'Acme Hosting']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.businesses.store'), $this->adminPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_business_creation_requires_billing_permission(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());

        $this->actingAs($user)
            ->postJson(route('admin.billing.businesses.store'), $this->adminPayload())
            ->assertForbidden();
    }

    public function test_admin_can_update_business(): void
    {
        $business = Business::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.businesses.update', $business), $this->adminPayload(['name' => 'New Name']))
            ->assertOk();

        $this->assertSame('New Name', $business->fresh()->name);
    }

    public function test_business_cannot_be_deleted_when_attached_clients_exist(): void
    {
        $business = Business::factory()->create();
        Client::factory()->for($business)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.businesses.destroy', $business))
            ->assertStatus(422);

        $this->assertModelExists($business);
    }

    public function test_business_can_be_deleted_when_empty(): void
    {
        $business = Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.businesses.destroy', $business))
            ->assertOk();

        $this->assertModelMissing($business);
    }

    public function test_businesses_data_endpoint_returns_rows(): void
    {
        Business::factory()->count(3)->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.businesses.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_create_cost_provider_and_token_validation_runs(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account*' => Http::response(['account' => ['email' => 'x@y.z']], 200),
        ]);

        $business = Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), [
                'business_id' => $business->id,
                'slug' => 'digitalocean',
                'display_name' => 'DigitalOcean (Prod)',
                'token' => str_repeat('a', 64),
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('token_valid', true);

        $this->assertDatabaseHas('cost_providers', [
            'business_id' => $business->id,
            'display_name' => 'DigitalOcean (Prod)',
            'enabled' => true,
        ]);
    }

    public function test_cost_provider_create_flags_invalid_token(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $business = Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), [
                'business_id' => $business->id,
                'slug' => 'digitalocean',
                'display_name' => 'DigitalOcean',
                'token' => str_repeat('b', 64),
            ])
            ->assertOk()
            ->assertJsonPath('token_valid', false);
    }

    public function test_cost_provider_update_without_token_keeps_existing_credentials(): void
    {
        $provider = CostProvider::factory()->create([
            'credentials' => ['token' => 'original-secret'],
            'display_name' => 'Old',
        ]);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.cost-providers.update', $provider), [
                'display_name' => 'Renamed',
                'enabled' => true,
            ])
            ->assertOk();

        $fresh = $provider->fresh();
        $this->assertSame('Renamed', $fresh->display_name);
        $this->assertSame('original-secret', $fresh->credentials['token']);
    }

    public function test_cost_provider_token_rotation_replaces_token(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account*' => Http::response(['account' => ['email' => 'x@y.z']], 200),
        ]);

        $provider = CostProvider::factory()->create([
            'credentials' => ['token' => 'original-secret'],
        ]);
        $newToken = str_repeat('c', 64);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.cost-providers.update', $provider), [
                'display_name' => $provider->display_name,
                'enabled' => true,
                'token' => $newToken,
            ])
            ->assertOk();

        $this->assertSame($newToken, $provider->fresh()->credentials['token']);
    }

    public function test_manual_sync_dispatches_job(): void
    {
        Bus::fake();

        $provider = CostProvider::factory()->create(['enabled' => true]);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync', $provider))
            ->assertOk();

        Bus::assertDispatched(
            SyncDigitalOceanProjectsJob::class,
            fn (SyncDigitalOceanProjectsJob $job) => $job->costProviderId === $provider->id,
        );
    }

    public function test_manual_sync_refuses_disabled_provider(): void
    {
        Bus::fake();

        $provider = CostProvider::factory()->create(['enabled' => false]);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync', $provider))
            ->assertStatus(422);

        Bus::assertNotDispatched(SyncDigitalOceanProjectsJob::class);
    }

    public function test_cost_provider_data_endpoint_scopes_to_current_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        CostProvider::factory()->for($business)->create();
        CostProvider::factory()->for($other)->create();

        $admin = $this->createAdmin();
        // Select the first business in session via the switcher.
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.cost-providers.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);
    }

    public function test_user_role_cannot_manage_cost_providers(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());
        $provider = CostProvider::factory()->create();

        $this->actingAs($user)
            ->postJson(route('admin.billing.cost-providers.sync', $provider))
            ->assertForbidden();
    }

    public function test_cost_provider_default_sync_status_renders_in_data_endpoint(): void
    {
        $business = Business::factory()->create();
        CostProvider::factory()->for($business)->create();
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.billing.cost-providers.data', ['draw' => 1, 'length' => 50]))
            ->assertOk();

        $this->assertSame(SyncStatus::Never->value, 'never');
        $this->assertStringContainsString('Never synced', (string) $response->json('data.0.last_sync_status'));
    }

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Hosting',
            'contact_email' => 'hello@acme.test',
            'notification_email' => 'invoices@acme.test',
            'invoice_number_prefix' => 'ACM-',
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD', 'USD'],
            'fx_source' => 'bank_of_canada',
            'daily_reminder_time' => '08:00',
        ], $overrides);
    }
}
