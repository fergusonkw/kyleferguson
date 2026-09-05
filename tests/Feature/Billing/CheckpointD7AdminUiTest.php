<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\Smtp2goRegion;
use App\Enums\Role as RoleEnum;
use App\Jobs\Billing\SyncProviderBillingJob;
use App\Jobs\Billing\SyncProviderResourcesJob;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\BillingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reconciliation surface and the SMTP2GO additions to the cost-provider
 * form.
 */
final class CheckpointD7AdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_reconciliation_page_requires_billing_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.reconciliation.index'))
            ->assertForbidden();
    }

    public function test_reconciliation_page_requires_authentication(): void
    {
        $this->get(route('admin.billing.reconciliation.index'))->assertRedirect(route('login'));
    }

    public function test_reconciliation_page_loads_for_admin(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index'))
            ->assertOk()
            ->assertSee('Reconciliation');
    }

    public function test_reconciliation_page_handles_having_no_business(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index'))
            ->assertOk()
            ->assertSee('No businesses configured yet');
    }

    public function test_reconciliation_page_shows_the_periods_figures(): void
    {
        [$business, $client, $project] = $this->scaffold();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();

        CostLineItem::factory()->for($provider)->forPeriod('2026-08')->usd(120.5)
            ->attributedTo($project)->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index', ['period' => '2026-08']))
            ->assertOk()
            ->assertSee('$120.50')
            ->assertSee($client->name);
    }

    public function test_reconciliation_page_explains_an_unavailable_cost_gap(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index'))
            ->assertOk()
            ->assertSee('No self-reporting provider');
    }

    public function test_reconciliation_page_falls_back_to_the_current_period_when_given_junk(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index', ['period' => 'not-a-period']))
            ->assertOk()
            ->assertSee(BillingPeriod::label(BillingPeriod::current()));
    }

    public function test_line_items_endpoint_returns_the_periods_rows(): void
    {
        [$business, , $project] = $this->scaffold();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();

        CostLineItem::factory()->for($provider)->forPeriod('2026-08')->usd(10.0)
            ->attributedTo($project)->create();
        CostLineItem::factory()->for($provider)->forPeriod('2026-07')->usd(99.0)->create();

        $response = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.reconciliation.line-items', ['period' => '2026-08']))
            ->assertOk();

        $response->assertJsonPath('recordsTotal', 1);
        $this->assertStringContainsString('Attributed', $response->json('data.0.state'));
        $this->assertSame('$10.00 USD', $response->json('data.0.cost'));
    }

    public function test_line_items_endpoint_scopes_to_the_current_business(): void
    {
        $mine = Business::factory()->create(['name' => 'A Business']);
        $theirs = Business::factory()->create(['name' => 'Z Business']);

        CostLineItem::factory()->for(CostProvider::factory()->for($mine)->create())
            ->forPeriod('2026-08')->create();
        CostLineItem::factory()->for(CostProvider::factory()->for($theirs)->create())
            ->forPeriod('2026-08')->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.reconciliation.line-items', ['period' => '2026-08']))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);
    }

    public function test_line_items_endpoint_requires_billing_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->getJson(route('admin.billing.reconciliation.line-items'))
            ->assertForbidden();
    }

    public function test_billing_dashboard_shows_live_cost_tiles(): void
    {
        [$business, , $project] = $this->scaffold();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();

        CostLineItem::factory()->for($provider)->forPeriod(BillingPeriod::current())->usd(42.0)
            ->attributedTo($project)->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('$42.00')
            ->assertSee('Attributed');
    }

    public function test_cost_providers_page_renders_the_smtp2go_form_fields(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.cost-providers.index'))
            ->assertOk()
            ->assertSee('SMTP2GO')
            ->assertSee('Monthly Fee')
            ->assertSee('API Region')
            ->assertSee('name="client_id"', false);
    }

    public function test_provider_row_offers_only_the_syncs_it_supports(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        CostProvider::factory()->for($business)->smtp2go()->forClient($client)->create();

        $response = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.cost-providers.data'))
            ->assertOk();

        $actions = $response->json('data.0.actions');
        $this->assertStringContainsString('sync-provider-billing', $actions);
        $this->assertStringNotContainsString('sync-provider"', $actions);
    }

    public function test_digitalocean_row_offers_resource_sync_but_not_billing_sync(): void
    {
        $business = Business::factory()->create();
        CostProvider::factory()->for($business)->create();

        $actions = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.cost-providers.data'))
            ->assertOk()
            ->json('data.0.actions');

        $this->assertStringContainsString('sync-provider"', $actions);
        $this->assertStringNotContainsString('sync-provider-billing', $actions);
    }

    public function test_reconciliation_appears_in_the_sidebar_for_billing_users(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee(route('admin.billing.reconciliation.index'));
    }

    public function test_admin_can_create_an_smtp2go_provider(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response(['data' => ['cycle_used' => 1]])]);
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), $this->smtp2goPayload($business, $client))
            ->assertOk()
            ->assertJson(['success' => true]);

        $provider = CostProvider::query()->where('slug', CostProviderSlug::Smtp2go)->firstOrFail();

        $this->assertSame($client->id, $provider->client_id);
        $this->assertSame('15.00', $provider->config('monthly_fee'));
        $this->assertSame('USD', $provider->config('fee_currency'));
        $this->assertSame(Smtp2goRegion::Us->value, $provider->config('region'));
        $this->assertArrayHasKey('api_key', $provider->credentials);
    }

    public function test_smtp2go_provider_requires_a_client(): void
    {
        $business = Business::factory()->create();
        $payload = $this->smtp2goPayload($business);
        unset($payload['client_id']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_smtp2go_provider_requires_a_monthly_fee(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $payload = $this->smtp2goPayload($business, $client);
        unset($payload['monthly_fee']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monthly_fee']);
    }

    public function test_smtp2go_provider_rejects_a_client_from_another_business(): void
    {
        $business = Business::factory()->create();
        $otherClient = Client::factory()->for(Business::factory()->create())->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), $this->smtp2goPayload($business, $otherClient))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_smtp2go_provider_rejects_a_malformed_currency(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), $this->smtp2goPayload($business, $client, [
                'fee_currency' => 'DOLLARS',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fee_currency']);
    }

    public function test_digitalocean_provider_does_not_take_smtp2go_config(): void
    {
        Http::fake(['api.digitalocean.com/*' => Http::response(['account' => []])]);
        $business = Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), [
                'business_id' => $business->id,
                'slug' => CostProviderSlug::DigitalOcean->value,
                'display_name' => 'DigitalOcean',
                'token' => 'dop_v1_'.Str::random(64),
                'enabled' => true,
            ])
            ->assertOk();

        $provider = CostProvider::query()->where('slug', CostProviderSlug::DigitalOcean)->firstOrFail();

        $this->assertNull($provider->client_id);
        $this->assertSame([], $provider->config ?? []);
        $this->assertArrayHasKey('token', $provider->credentials);
    }

    public function test_edit_payload_exposes_config_and_capabilities(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->forClient($client)->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.cost-providers.edit', $provider))
            ->assertOk()
            ->assertJsonPath('provider.account_per_client', true)
            ->assertJsonPath('provider.supports_billing_sync', true)
            ->assertJsonPath('provider.supports_resource_sync', false)
            ->assertJsonPath('provider.fee_currency', 'USD')
            ->assertJsonPath('provider.client_id', $client->id);
    }

    public function test_updating_an_smtp2go_provider_changes_its_fee(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->forClient($client)->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.cost-providers.update', $provider), [
                'display_name' => 'SMTP2GO — Acme',
                'enabled' => true,
                'client_id' => $client->id,
                'region' => Smtp2goRegion::Global->value,
                'monthly_fee' => '25.00',
                'fee_currency' => 'usd',
            ])
            ->assertOk();

        $fresh = $provider->fresh();
        $this->assertSame('25.00', $fresh->config('monthly_fee'));
        $this->assertSame('USD', $fresh->config('fee_currency'));
        $this->assertSame('SMTP2GO — Acme', $fresh->display_name);
    }

    public function test_billing_sync_action_queues_the_job(): void
    {
        Bus::fake();
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync-billing', $provider), ['period' => '2026-08'])
            ->assertOk();

        Bus::assertDispatched(
            SyncProviderBillingJob::class,
            fn (SyncProviderBillingJob $job): bool => $job->costProviderId === $provider->id && $job->period === '2026-08',
        );
    }

    public function test_billing_sync_defaults_to_the_current_period(): void
    {
        Bus::fake();
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync-billing', $provider))
            ->assertOk();

        Bus::assertDispatched(
            SyncProviderBillingJob::class,
            fn (SyncProviderBillingJob $job): bool => $job->period === BillingPeriod::current(),
        );
    }

    public function test_billing_sync_refuses_a_provider_without_a_billing_adapter(): void
    {
        Bus::fake();
        $provider = CostProvider::factory()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync-billing', $provider))
            ->assertStatus(422);

        Bus::assertNotDispatched(SyncProviderBillingJob::class);
    }

    public function test_billing_sync_refuses_a_disabled_provider(): void
    {
        Bus::fake();
        $provider = CostProvider::factory()->smtp2go()->create(['enabled' => false]);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync-billing', $provider))
            ->assertStatus(422);

        Bus::assertNotDispatched(SyncProviderBillingJob::class);
    }

    public function test_resource_sync_refuses_a_provider_with_no_inventory(): void
    {
        Bus::fake();
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.sync', $provider))
            ->assertStatus(422);

        Bus::assertNotDispatched(SyncProviderResourcesJob::class);
    }

    public function test_available_clients_lists_the_current_business_clients(): void
    {
        $business = Business::factory()->create(['name' => 'A Business']);
        $client = Client::factory()->for($business)->create();
        Client::factory()->for(Business::factory()->create(['name' => 'Z Business']))->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.cost-providers.available-clients'))
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.id', $client->id);
    }

    public function test_unattributed_resources_appear_on_the_reconciliation_page(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();
        ProviderResource::factory()->for($provider, 'costProvider')->create(['name' => 'Orphan Account']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.reconciliation.index'))
            ->assertOk()
            ->assertSee('Unattributed resources')
            ->assertSee('Orphan Account');
    }

    /**
     * @return array{0: Business, 1: Client, 2: Project}
     */
    private function scaffold(): array
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();

        return [$business, $client, $project];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function smtp2goPayload(Business $business, ?Client $client = null, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $business->id,
            'client_id' => $client?->id,
            'slug' => CostProviderSlug::Smtp2go->value,
            'display_name' => 'SMTP2GO — Acme',
            'token' => 'api-'.Str::random(40),
            'region' => Smtp2goRegion::Us->value,
            'monthly_fee' => '15.00',
            'fee_currency' => 'USD',
            'enabled' => true,
        ], $overrides);
    }
}
