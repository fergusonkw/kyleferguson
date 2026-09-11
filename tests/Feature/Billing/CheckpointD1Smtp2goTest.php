<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\ProjectStatus;
use App\Enums\Billing\Smtp2goRegion;
use App\Enums\Billing\SyncStatus;
use App\Exceptions\Billing\ProviderConfigurationException;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\ProviderAdapterRegistry;
use App\Services\Billing\Smtp2go\BillingSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * SMTP2GO ingestion: an account-per-client provider with usage telemetry but
 * no cost API, so the charge is the operator-entered flat fee.
 */
final class CheckpointD1Smtp2goTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    /** @var array<string, mixed> */
    private array $cycleResponse = [];

    private bool $cycleStubbed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_registry_resolves_the_smtp2go_billing_adapter(): void
    {
        $registry = app(ProviderAdapterRegistry::class);

        $this->assertTrue($registry->supportsBillingSync(CostProviderSlug::Smtp2go));
        $this->assertInstanceOf(BillingSync::class, $registry->billingSyncFor(CostProviderSlug::Smtp2go));
        $this->assertContains(CostProviderSlug::Smtp2go, $registry->billingSyncSlugs());
    }

    public function test_credentials_validate_against_the_cycle_endpoint(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->assertTrue(app(BillingSync::class)->validateCredentials($provider));
    }

    public function test_credentials_are_invalid_when_the_key_is_rejected(): void
    {
        Http::fake([
            '*/stats/email_cycle' => Http::response([
                'data' => ['error' => 'You do not have permission to access this API endpoint'],
            ], 400),
        ]);
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->assertFalse(app(BillingSync::class)->validateCredentials($provider));
    }

    public function test_credentials_are_invalid_when_the_body_carries_an_error_despite_a_200(): void
    {
        Http::fake([
            '*/stats/email_cycle' => Http::response(['data' => ['error' => 'ENDPOINT_PERMISSION_DENIED']], 200),
        ]);
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->assertFalse(app(BillingSync::class)->validateCredentials($provider));
    }

    public function test_the_configured_region_selects_the_api_host(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['config' => ['region' => Smtp2goRegion::Eu->value, 'monthly_fee' => '15.00', 'fee_currency' => 'USD']]);

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://eu-api.smtp2go.com/v3'));
    }

    public function test_api_key_is_sent_as_a_header(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['credentials' => ['api_key' => 'api-known-value']]);

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        Http::assertSent(fn ($request): bool => $request->header('X-Smtp2go-Api-Key') === ['api-known-value']);
    }

    public function test_sync_writes_one_line_item_at_the_configured_fee(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go(15.0)->create();

        $written = app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertSame(1, $written);
        $this->assertSame(1, CostLineItem::count());

        $line = CostLineItem::query()->firstOrFail();
        $this->assertSame(CostCategory::Email, $line->category);
        $this->assertSame('15.0000', $line->source_amount);
        $this->assertSame('USD', $line->source_currency);
        $this->assertSame('15.0000', $line->usd_amount);
        $this->assertSame('0.0000', $line->usd_tax);
        $this->assertSame(self::PERIOD, $line->period);
        $this->assertSame('smtp2go:subscription', $line->source_reference);
        $this->assertSame(SyncStatus::Success, $provider->fresh()->last_sync_status);
    }

    public function test_line_description_carries_usage_against_the_plan_allowance(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create(['display_name' => 'SMTP2GO — Acme']);

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertStringContainsString('8,412 of 10,000 emails', CostLineItem::query()->firstOrFail()->description);
    }

    public function test_cycle_usage_is_stored_in_line_metadata(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $metadata = CostLineItem::query()->firstOrFail()->metadata;

        $this->assertSame(8412, $metadata['cycle_used']);
        $this->assertSame(10000, $metadata['cycle_max']);
        $this->assertSame(1588, $metadata['cycle_remaining']);
        $this->assertTrue($metadata['cycle_covers_period']);
        $this->assertFalse($metadata['over_quota']);
    }

    public function test_backfilling_an_older_period_flags_that_usage_does_not_describe_it(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        app(BillingSync::class)->syncBilling($provider, '2026-03');

        $metadata = CostLineItem::query()->firstOrFail()->metadata;
        $this->assertFalse($metadata['cycle_covers_period']);
    }

    public function test_over_quota_usage_is_flagged(): void
    {
        $this->fakeCycle(['cycle_used' => 12000, 'cycle_remaining' => 0, 'cycle_max' => 10000]);
        $provider = CostProvider::factory()->smtp2go()->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertTrue(CostLineItem::query()->firstOrFail()->metadata['over_quota']);
    }

    public function test_sync_stores_the_raw_cycle_payload(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $payload = ProviderBillingPayload::query()->firstOrFail();
        $this->assertSame(self::PERIOD, $payload->period);
        $this->assertSame(8412, $payload->raw_payload['cycle_used']);
        $this->assertSame('2026-08-01', $payload->source_key);
        $this->assertTrue(CostLineItem::query()->firstOrFail()->sourcePayload->is($payload));
    }

    public function test_resync_is_idempotent_and_keeps_one_payload_and_one_line(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $sync = app(BillingSync::class);

        $sync->syncBilling($provider, self::PERIOD);
        $sync->syncBilling($provider, self::PERIOD);

        $this->assertSame(1, CostLineItem::count());
        $this->assertSame(1, ProviderBillingPayload::count());
        $this->assertSame(1, ProviderResource::count());
    }

    public function test_resync_refreshes_usage_rather_than_appending_history(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $sync = app(BillingSync::class);
        $sync->syncBilling($provider, self::PERIOD);

        $this->fakeCycle(['cycle_used' => 9001, 'cycle_remaining' => 999, 'cycle_max' => 10000]);
        $sync->syncBilling($provider, self::PERIOD);

        $this->assertSame(1, ProviderBillingPayload::count());
        $this->assertSame(9001, ProviderBillingPayload::query()->firstOrFail()->raw_payload['cycle_used']);
        $this->assertSame(9001, CostLineItem::query()->firstOrFail()->metadata['cycle_used']);
    }

    public function test_each_period_gets_its_own_line_item(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $sync = app(BillingSync::class);

        $sync->syncBilling($provider, '2026-07');
        $sync->syncBilling($provider, '2026-08');

        $this->assertSame(2, CostLineItem::count());
        $this->assertSame(1, ProviderResource::count());
    }

    public function test_a_non_usd_fee_is_converted_to_the_usd_cost_basis(): void
    {
        $this->fakeCycle();
        FxRate::factory()->pair('CAD', 'USD')->forPeriod(self::PERIOD)->withRate(0.75)->create();
        $provider = CostProvider::factory()->smtp2go(20.0, 'CAD')->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $line = CostLineItem::query()->firstOrFail();
        $this->assertSame('20.0000', $line->source_amount);
        $this->assertSame('CAD', $line->source_currency);
        $this->assertSame('15.0000', $line->usd_amount);
    }

    public function test_sync_creates_one_synthetic_subscription_resource(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create(['display_name' => 'SMTP2GO — Acme']);

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $resource = ProviderResource::query()->firstOrFail();
        $this->assertSame('subscription', $resource->resource_type);
        $this->assertSame('account', $resource->provider_resource_id);
        $this->assertSame('SMTP2GO — Acme', $resource->name);
        $this->assertSame(8412, $resource->metadata['cycle_used']);
        $this->assertTrue(CostLineItem::query()->firstOrFail()->providerResource->is($resource));
    }

    public function test_account_auto_attaches_to_the_clients_only_active_project(): void
    {
        $this->fakeCycle();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->smtp2go()->forClient($client)->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $resource = ProviderResource::query()->firstOrFail();
        $this->assertSame($project->id, $resource->project_id);

        $assignment = ResourceAssignment::query()->firstOrFail();
        $this->assertSame($project->id, $assignment->project_id);
        $this->assertNull($assignment->observed_to);
        $this->assertTrue($assignment->isCurrent());
    }

    public function test_account_stays_unattributed_when_the_client_has_several_active_projects(): void
    {
        $this->fakeCycle();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        Project::factory()->count(2)->for($client)->create();
        $provider = CostProvider::factory()->smtp2go()->forClient($client)->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertNull(ProviderResource::query()->firstOrFail()->project_id);
        $this->assertSame(0, ResourceAssignment::count());
    }

    public function test_account_stays_unattributed_when_the_client_has_no_active_project(): void
    {
        $this->fakeCycle();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        Project::factory()->for($client)->create(['status' => ProjectStatus::Terminated]);
        $provider = CostProvider::factory()->smtp2go()->forClient($client)->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertNull(ProviderResource::query()->firstOrFail()->project_id);
        $this->assertSame(0, ResourceAssignment::count());
    }

    public function test_account_stays_unattributed_without_a_client_link(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertNull(ProviderResource::query()->firstOrFail()->project_id);
        $this->assertSame(0, ResourceAssignment::count());
    }

    public function test_auto_assignment_only_happens_once_and_does_not_override_a_manual_move(): void
    {
        $this->fakeCycle();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $original = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->smtp2go()->forClient($client)->create();
        $sync = app(BillingSync::class);

        $sync->syncBilling($provider, self::PERIOD);

        $moved = Project::factory()->for($client)->create();
        $resource = ProviderResource::query()->firstOrFail();
        $resource->update(['project_id' => $moved->id]);

        $sync->syncBilling($provider, '2026-09');

        $this->assertSame($moved->id, ProviderResource::query()->firstOrFail()->project_id);
        $this->assertSame(1, ResourceAssignment::count());
        $this->assertNotSame($original->id, ProviderResource::query()->firstOrFail()->project_id);
    }

    public function test_missing_monthly_fee_fails_the_sync_loudly(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['config' => ['region' => 'global']]);

        $this->assertSyncFails($provider, ProviderConfigurationException::class, 'monthly_fee');
        $this->assertSame(0, CostLineItem::count());
    }

    public function test_non_numeric_monthly_fee_fails_the_sync(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['config' => ['monthly_fee' => 'fifteen dollars', 'fee_currency' => 'USD']]);

        $this->assertSyncFails($provider, ProviderConfigurationException::class, 'not a number');
    }

    public function test_negative_monthly_fee_fails_the_sync(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['config' => ['monthly_fee' => '-5', 'fee_currency' => 'USD']]);

        $this->assertSyncFails($provider, ProviderConfigurationException::class, 'negative');
    }

    public function test_malformed_fee_currency_fails_the_sync(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['config' => ['monthly_fee' => '15', 'fee_currency' => 'DOLLARS']]);

        $this->assertSyncFails($provider, ProviderConfigurationException::class, '3-letter code');
    }

    public function test_upstream_failure_marks_the_provider_failed(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response(['message' => 'server error'], 500)]);
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->assertSyncFails($provider, Throwable::class, null);
        $this->assertSame(0, CostLineItem::count());
        $this->assertSame(0, ProviderBillingPayload::count());
    }

    public function test_a_rejected_key_fails_the_sync_rather_than_ingesting_zero(): void
    {
        Http::fake([
            '*/stats/email_cycle' => Http::response(['data' => ['error' => 'ENDPOINT_PERMISSION_DENIED']], 200),
        ]);
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->assertSyncFails($provider, RuntimeException::class, 'ENDPOINT_PERMISSION_DENIED');
        $this->assertSame(0, CostLineItem::count());
    }

    public function test_a_provider_without_an_api_key_fails_the_sync(): void
    {
        Http::fake();
        $provider = CostProvider::factory()->smtp2go()->create();
        $provider->update(['credentials' => []]);

        $this->assertSyncFails($provider, RuntimeException::class, 'no SMTP2GO API key');
        Http::assertNothingSent();
    }

    /**
     * @param  class-string<Throwable>  $expected
     */
    private function assertSyncFails(CostProvider $provider, string $expected, ?string $messageFragment): void
    {
        try {
            app(BillingSync::class)->syncBilling($provider, self::PERIOD);
            $this->fail("Expected {$expected} to be thrown");
        } catch (Throwable $e) {
            $this->assertInstanceOf($expected, $e);

            if ($messageFragment !== null) {
                $this->assertStringContainsString($messageFragment, $e->getMessage());
            }
        }

        $fresh = $provider->fresh();
        $this->assertSame(SyncStatus::Failed, $fresh->last_sync_status);
        $this->assertNotNull($fresh->last_sync_error);
    }

    /**
     * Stub the cycle endpoint. Re-calling this replaces the response, which a
     * second `Http::fake()` would not do — the earliest matching stub wins.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeCycle(array $overrides = []): void
    {
        $fixture = $this->jsonFixture('smtp2go/email_cycle.json');
        $fixture['data'] = array_merge($fixture['data'], $overrides);
        $this->cycleResponse = $fixture;

        if ($this->cycleStubbed) {
            return;
        }

        $this->cycleStubbed = true;
        Http::fake(fn () => Http::response($this->cycleResponse));
    }
}
