<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\BillingCategory;
use App\Enums\Billing\SyncStatus;
use App\Jobs\Billing\SyncDigitalOceanBillingJob;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\CostAttributor;
use App\Services\Billing\DigitalOcean\BillingSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

final class CheckpointDIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-06';

    /** @var array<string, mixed> */
    private array $fakeInvoiceDetail = [];

    /** @var array<string, mixed> */
    private array $fakeInvoicesList = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeInvoicesList = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/digitalocean/invoices_list.json')),
            true,
        );

        $this->fakeInvoiceDetail = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/digitalocean/invoice_detail.json')),
            true,
        );
    }

    public function test_billing_sync_stores_payload_and_derives_line_items(): void
    {
        $provider = CostProvider::factory()->create();
        $this->fakeDoInvoicesApi();

        $sync = app(BillingSync::class);
        $written = $sync->syncBilling($provider, self::PERIOD);

        $this->assertSame(4, $written);
        $this->assertSame(1, ProviderBillingPayload::count());
        $this->assertSame(4, CostLineItem::count());

        $payload = ProviderBillingPayload::first();
        $this->assertSame(self::PERIOD, $payload->period);
        $this->assertNotEmpty($payload->content_hash);
        $this->assertSame(SyncStatus::Success, $provider->fresh()->last_sync_status);
    }

    public function test_billing_sync_maps_categories_from_do_product(): void
    {
        $provider = CostProvider::factory()->create();
        $this->fakeDoInvoicesApi();

        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $categories = CostLineItem::query()
            ->pluck('category')
            ->map(fn ($c) => $c->value)
            ->sort()
            ->values()
            ->all();

        $this->assertContains(BillingCategory::Droplet->value, $categories);
        $this->assertContains(BillingCategory::Database->value, $categories);
        $this->assertContains(BillingCategory::Tax->value, $categories);
    }

    public function test_billing_sync_is_idempotent_for_unchanged_payload(): void
    {
        $provider = CostProvider::factory()->create();
        $this->fakeDoInvoicesApi();

        $sync = app(BillingSync::class);
        $sync->syncBilling($provider, self::PERIOD);
        $written = $sync->syncBilling($provider, self::PERIOD);

        $this->assertSame(0, $written);
        $this->assertSame(1, ProviderBillingPayload::count());
        $this->assertSame(4, CostLineItem::count());
    }

    public function test_billing_sync_stores_new_payload_when_content_changes(): void
    {
        $provider = CostProvider::factory()->create();
        $this->fakeDoInvoicesApi();
        app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->addExtraInvoiceItem(10.0);
        $written = app(BillingSync::class)->syncBilling($provider, self::PERIOD);

        $this->assertGreaterThan(0, $written);
        $this->assertSame(2, ProviderBillingPayload::count());
    }

    public function test_billing_sync_returns_zero_when_period_not_found(): void
    {
        $provider = CostProvider::factory()->create();
        $this->fakeDoInvoicesApi();

        $written = app(BillingSync::class)->syncBilling($provider, '2020-01');

        $this->assertSame(0, $written);
    }

    public function test_billing_sync_marks_provider_failed_on_api_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.digitalocean.com/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $provider = CostProvider::factory()->create();

        try {
            app(BillingSync::class)->syncBilling($provider, self::PERIOD);
            $this->fail('Expected exception');
        } catch (Throwable) {
        }

        $this->assertSame(SyncStatus::Failed, $provider->fresh()->last_sync_status);
    }

    public function test_cost_attributor_links_line_items_to_projects_via_resource_assignments(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();

        $resource = ProviderResource::factory()->create([
            'cost_provider_id' => $provider->id,
            'provider_resource_id' => 'droplet-resource-123',
            'project_id' => $project->id,
        ]);

        \App\Models\Billing\ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => $project->id,
            'provider_project_uuid' => 'some-uuid',
            'observed_from' => now()->subMonth()->startOfMonth(),
            'observed_to' => null,
        ]);

        $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod(self::PERIOD)->create();
        $unattributedItem = CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'provider_resource_id' => $resource->id,
            'project_id' => null,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
        ]);

        $attributor = app(CostAttributor::class);
        $attributed = $attributor->attributeForProvider($provider, self::PERIOD);

        $this->assertSame(1, $attributed);
        $this->assertSame($project->id, $unattributedItem->fresh()->project_id);
    }

    public function test_cost_attributor_leaves_overhead_items_unattributed(): void
    {
        $provider = CostProvider::factory()->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod(self::PERIOD)->create();

        CostLineItem::factory()->overhead()->create([
            'cost_provider_id' => $provider->id,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
        ]);

        $attributor = app(CostAttributor::class);
        $attributed = $attributor->attributeForProvider($provider, self::PERIOD);

        $this->assertSame(0, $attributed);
    }

    public function test_reconciliation_summary_returns_correct_counts(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();
        $payload = ProviderBillingPayload::factory()->for($provider)->forPeriod(self::PERIOD)->create();

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => $project->id,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 50.0,
        ]);

        CostLineItem::factory()->create([
            'cost_provider_id' => $provider->id,
            'project_id' => null,
            'source_payload_id' => $payload->id,
            'period' => self::PERIOD,
            'usd_amount' => 5.0,
        ]);

        $attributor = app(CostAttributor::class);
        $summary = $attributor->reconciliationSummary($provider, self::PERIOD);

        $this->assertSame(1, $summary['unattributed_items']);
        $this->assertEqualsWithDelta(5.0, $summary['unattributed_usd'], 0.001);
        $this->assertSame(1, $summary['attributed_items']);
        $this->assertEqualsWithDelta(50.0, $summary['attributed_usd'], 0.001);
    }

    public function test_job_can_be_dispatched(): void
    {
        Bus::fake();

        SyncDigitalOceanBillingJob::dispatch(1, self::PERIOD);

        Bus::assertDispatched(
            SyncDigitalOceanBillingJob::class,
            fn (SyncDigitalOceanBillingJob $job): bool => $job->costProviderId === 1 && $job->period === self::PERIOD,
        );
    }

    private function fakeDoInvoicesApi(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/customers/my/invoices*' => function ($request) {
                $url = $request->url();

                if (preg_match('/\/invoices\/([^?]+)/', $url, $matches)) {
                    return Http::response($this->fakeInvoiceDetail, 200);
                }

                return Http::response($this->fakeInvoicesList, 200);
            },
        ]);
    }

    private function addExtraInvoiceItem(float $amount): void
    {
        $this->fakeInvoiceDetail['invoice_items'][] = [
            'product' => 'Droplets',
            'resource_uuid' => 'extra-droplet-'.rand(1000, 9999),
            'description' => 'extra-server',
            'amount' => (string) $amount,
            'tax_amount' => '0.00',
            'project_name' => 'extra-project',
        ];
    }
}
