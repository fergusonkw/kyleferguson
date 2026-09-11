<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostAttributionState;
use App\Enums\Billing\CostCategory;
use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\FxRateSource;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 cost-ingestion schema: the new tables, their idempotency keys, and
 * the derived attribution state that reporting leans on.
 */
final class CheckpointD0SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cost_provider_stores_config_unencrypted_and_credentials_encrypted(): void
    {
        $provider = CostProvider::factory()->create([
            'credentials' => ['api_key' => 'api-secret-value'],
            'config' => ['region' => 'us', 'monthly_fee' => '15.00', 'fee_currency' => 'USD'],
        ]);

        $raw = $this->getRawCostProviderRow($provider->id);

        $this->assertStringNotContainsString('api-secret-value', (string) $raw->credentials);
        $this->assertStringContainsString('us', (string) $raw->config);

        $fresh = $provider->fresh();
        $this->assertSame('api-secret-value', $fresh->credentials['api_key']);
        $this->assertSame('15.00', $fresh->config('monthly_fee'));
        $this->assertSame('USD', $fresh->config('fee_currency'));
        $this->assertSame('fallback', $fresh->config('missing.key', 'fallback'));
    }

    public function test_cost_provider_can_be_linked_to_a_client(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        $provider = CostProvider::factory()->for($business)->create([
            'client_id' => $client->id,
            'slug' => CostProviderSlug::Smtp2go,
        ]);

        $this->assertTrue($provider->client->is($client));
        $this->assertTrue($provider->slug->isAccountPerClient());
    }

    public function test_deleting_a_client_nulls_the_provider_link_but_keeps_the_provider(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $provider = CostProvider::factory()->for($business)->create(['client_id' => $client->id]);

        $client->delete();

        $fresh = $provider->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->client_id);
    }

    public function test_digitalocean_provider_is_not_account_per_client(): void
    {
        $this->assertFalse(CostProviderSlug::DigitalOcean->isAccountPerClient());
    }

    public function test_payload_hash_is_stable_across_key_order(): void
    {
        $a = ['cycle_used' => 10, 'cycle_max' => 1000, 'nested' => ['b' => 2, 'a' => 1]];
        $b = ['nested' => ['a' => 1, 'b' => 2], 'cycle_max' => 1000, 'cycle_used' => 10];

        $this->assertSame(
            ProviderBillingPayload::hashPayload($a),
            ProviderBillingPayload::hashPayload($b),
        );
    }

    public function test_payload_hash_changes_when_a_value_changes(): void
    {
        $original = ['cycle_used' => 10];
        $restated = ['cycle_used' => 11];

        $this->assertNotSame(
            ProviderBillingPayload::hashPayload($original),
            ProviderBillingPayload::hashPayload($restated),
        );
    }

    public function test_payload_hash_preserves_list_ordering(): void
    {
        $this->assertNotSame(
            ProviderBillingPayload::hashPayload(['items' => ['a', 'b']]),
            ProviderBillingPayload::hashPayload(['items' => ['b', 'a']]),
        );
    }

    public function test_identical_payload_for_same_provider_and_period_is_rejected(): void
    {
        $provider = CostProvider::factory()->create();
        $payload = ['cycle_used' => 42];

        ProviderBillingPayload::factory()->for($provider)->withPayload($payload)->forPeriod('2026-08')->create();

        $this->expectException(QueryException::class);

        ProviderBillingPayload::factory()->for($provider)->withPayload($payload)->forPeriod('2026-08')->create();
    }

    public function test_restated_payload_for_same_period_is_allowed(): void
    {
        $provider = CostProvider::factory()->create();

        ProviderBillingPayload::factory()->for($provider)->withPayload(['cycle_used' => 42])->forPeriod('2026-08')->create();
        ProviderBillingPayload::factory()->for($provider)->withPayload(['cycle_used' => 43])->forPeriod('2026-08')->create();

        $this->assertSame(2, $provider->billingPayloads()->where('period', '2026-08')->count());
    }

    public function test_line_hash_is_deterministic_and_null_safe(): void
    {
        $first = CostLineItem::makeLineHash(['smtp2go', '2026-08', null, 'email']);
        $second = CostLineItem::makeLineHash(['smtp2go', '2026-08', null, 'email']);
        $different = CostLineItem::makeLineHash(['smtp2go', '2026-09', null, 'email']);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $different);
    }

    public function test_duplicate_line_hash_in_same_provider_period_is_rejected(): void
    {
        $provider = CostProvider::factory()->create();
        $hash = CostLineItem::makeLineHash(['smtp2go', '2026-08', 'subscription']);

        CostLineItem::factory()->for($provider)->forPeriod('2026-08')->create(['line_hash' => $hash]);

        $this->expectException(QueryException::class);

        CostLineItem::factory()->for($provider)->forPeriod('2026-08')->create(['line_hash' => $hash]);
    }

    public function test_same_line_hash_in_a_different_period_is_allowed(): void
    {
        $provider = CostProvider::factory()->create();
        $hash = CostLineItem::makeLineHash(['smtp2go', 'subscription']);

        CostLineItem::factory()->for($provider)->forPeriod('2026-08')->create(['line_hash' => $hash]);
        CostLineItem::factory()->for($provider)->forPeriod('2026-09')->create(['line_hash' => $hash]);

        $this->assertSame(2, $provider->costLineItems()->count());
    }

    public function test_attribution_state_reflects_project_and_category(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();

        $attributed = CostLineItem::factory()->attributedTo($project)->create();
        $unattributed = CostLineItem::factory()->create();
        $overhead = CostLineItem::factory()->ofCategory(CostCategory::Support)->create();

        $this->assertSame(CostAttributionState::Attributed, $attributed->attributionState());
        $this->assertSame(CostAttributionState::Unattributed, $unattributed->attributionState());
        $this->assertSame(CostAttributionState::Overhead, $overhead->attributionState());
    }

    public function test_non_attributable_category_reports_overhead_even_when_project_is_set(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();

        $line = CostLineItem::factory()
            ->ofCategory(CostCategory::Credit)
            ->attributedTo($project)
            ->create();

        $this->assertSame(CostAttributionState::Overhead, $line->attributionState());
    }

    public function test_usd_cost_basis_includes_provider_tax(): void
    {
        $line = CostLineItem::factory()->usd(100.0, 13.0)->create();

        $this->assertSame('113.0000', $line->usdCostBasis());
    }

    public function test_line_item_scopes_filter_by_business_and_period(): void
    {
        $mine = Business::factory()->create();
        $theirs = Business::factory()->create();

        $myProvider = CostProvider::factory()->for($mine)->create();
        $theirProvider = CostProvider::factory()->for($theirs)->create();

        CostLineItem::factory()->for($myProvider)->forPeriod('2026-08')->create();
        CostLineItem::factory()->for($myProvider)->forPeriod('2026-07')->create();
        CostLineItem::factory()->for($theirProvider)->forPeriod('2026-08')->create();

        $rows = CostLineItem::query()
            ->forBusiness($mine->id)
            ->forPeriod('2026-08')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($myProvider->id, $rows->first()->cost_provider_id);
    }

    public function test_line_item_relations_resolve(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();
        $resource = ProviderResource::factory()->for($provider, 'costProvider')->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->create();

        $line = CostLineItem::factory()
            ->forResource($resource)
            ->attributedTo($project)
            ->create(['source_payload_id' => $payload->id]);

        $this->assertTrue($line->costProvider->is($provider));
        $this->assertTrue($line->providerResource->is($resource));
        $this->assertTrue($line->project->is($project));
        $this->assertTrue($line->sourcePayload->is($payload));
        $this->assertTrue($project->costLineItems->contains($line));
        $this->assertTrue($resource->costLineItems->contains($line));
        $this->assertTrue($payload->lineItems->contains($line));
    }

    public function test_deleting_a_payload_keeps_its_derived_lines(): void
    {
        $provider = CostProvider::factory()->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->create();
        $line = CostLineItem::factory()->for($provider)->create(['source_payload_id' => $payload->id]);

        $payload->delete();

        $fresh = $line->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->source_payload_id);
    }

    public function test_fx_rate_pair_is_unique_per_period_and_source(): void
    {
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->create();

        $this->expectException(QueryException::class);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->create();
    }

    public function test_fx_rate_casts_and_stores_precision(): void
    {
        $rate = FxRate::factory()->pair('USD', 'CAD')->withRate(1.37425000)->create();

        $fresh = $rate->fresh();
        $this->assertSame('1.37425000', $fresh->rate);
        $this->assertSame(FxRateSource::BankOfCanada, $fresh->source);
    }

    public function test_same_pair_and_period_from_a_different_source_is_allowed(): void
    {
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->create();
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->create([
            'source' => FxRateSource::Internal,
        ]);

        $this->assertSame(2, FxRate::query()->where('period', '2026-08')->count());
    }

    private function getRawCostProviderRow(int $id): object
    {
        /** @var object $row */
        $row = CostProvider::query()
            ->getConnection()
            ->table('cost_providers')
            ->where('id', $id)
            ->first();

        return $row;
    }
}
