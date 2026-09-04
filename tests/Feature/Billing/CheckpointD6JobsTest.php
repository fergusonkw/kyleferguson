<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Exceptions\Billing\FxRateUnavailableException;
use App\Jobs\Billing\AttributeCostsJob;
use App\Jobs\Billing\FetchFxRateJob;
use App\Jobs\Billing\ReconciliationAlertJob;
use App\Jobs\Billing\SyncProviderBillingJob;
use App\Jobs\Billing\SyncProviderResourcesJob;
use App\Mail\Billing\ReconciliationAlert;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\ProviderAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Job routing, guard rails, and the operator alert.
 */
final class CheckpointD6JobsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_billing_job_routes_to_the_providers_adapter(): void
    {
        $this->fakeCycle();
        $provider = CostProvider::factory()->smtp2go()->create();

        $this->billingJob($provider->id)->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(1, CostLineItem::count());
        $this->assertSame(self::PERIOD, CostLineItem::query()->firstOrFail()->period);
    }

    public function test_billing_job_skips_a_provider_without_a_billing_adapter(): void
    {
        Http::fake();
        $provider = CostProvider::factory()->create();

        $this->billingJob($provider->id)->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(0, CostLineItem::count());
        Http::assertNothingSent();
    }

    public function test_billing_job_skips_a_disabled_provider(): void
    {
        Http::fake();
        $provider = CostProvider::factory()->smtp2go()->create(['enabled' => false]);

        $this->billingJob($provider->id)->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(0, CostLineItem::count());
        Http::assertNothingSent();
    }

    public function test_billing_job_skips_a_missing_provider(): void
    {
        Http::fake();

        $this->billingJob(9999)->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(0, CostLineItem::count());
    }

    public function test_billing_job_rejects_a_malformed_period(): void
    {
        Http::fake();
        $provider = CostProvider::factory()->smtp2go()->create();

        (new SyncProviderBillingJob($provider->id, 'August'))->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(0, CostLineItem::count());
        Http::assertNothingSent();
    }

    public function test_resource_job_skips_a_provider_with_no_inventory(): void
    {
        Http::fake();
        $provider = CostProvider::factory()->smtp2go()->create();

        (new SyncProviderResourcesJob($provider->id))->handle(app(ProviderAdapterRegistry::class));

        $this->assertSame(0, ProviderResource::count());
        Http::assertNothingSent();
    }

    public function test_attribute_job_attributes_the_periods_costs(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->create();
        $resource = ProviderResource::factory()->for($provider, 'costProvider')->create();

        ResourceAssignment::create([
            'provider_resource_id' => $resource->id,
            'project_id' => $project->id,
            'observed_from' => '2026-07-01',
            'observed_to' => null,
        ]);

        $line = CostLineItem::factory()->forResource($resource)->forPeriod(self::PERIOD)->create();

        (new AttributeCostsJob($business->id, self::PERIOD))->handle(app(\App\Services\Billing\CostAttributor::class));

        $this->assertSame($project->id, $line->fresh()->project_id);
    }

    public function test_attribute_job_skips_a_missing_business(): void
    {
        $line = CostLineItem::factory()->forPeriod(self::PERIOD)->create();

        (new AttributeCostsJob(9999, self::PERIOD))->handle(app(\App\Services\Billing\CostAttributor::class));

        $this->assertNull($line->fresh()->attributed_at);
    }

    public function test_fx_job_caches_the_rate(): void
    {
        Http::fake([
            '*/observations/FXUSDCAD/json*' => Http::response($this->jsonFixture('bankofcanada/fxusdcad.json')),
        ]);

        (new FetchFxRateJob('USD', 'CAD', self::PERIOD))->handle(app(\App\Services\Billing\FxRateService::class));

        $this->assertSame(1, FxRate::count());
        $this->assertSame('1.37500000', FxRate::query()->firstOrFail()->rate);
    }

    public function test_fx_job_rethrows_so_the_queue_retries(): void
    {
        Http::fake(['*/observations/*' => Http::response(['observations' => []])]);

        $this->expectException(FxRateUnavailableException::class);

        (new FetchFxRateJob('USD', 'CAD', self::PERIOD))->handle(app(\App\Services\Billing\FxRateService::class));
    }

    public function test_fx_job_rejects_a_malformed_period_without_requesting(): void
    {
        Http::fake();

        (new FetchFxRateJob('USD', 'CAD', 'nope'))->handle(app(\App\Services\Billing\FxRateService::class));

        $this->assertSame(0, FxRate::count());
        Http::assertNothingSent();
    }

    public function test_alert_is_sent_when_a_resource_is_unattributed(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['notification_email' => 'ops@example.test']);
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();
        ProviderResource::factory()->for($provider, 'costProvider')->create();

        $this->alertJob($business->id);

        Mail::assertSent(ReconciliationAlert::class, fn (ReconciliationAlert $mail): bool => $mail->hasTo('ops@example.test'));
    }

    public function test_alert_is_sent_when_a_cost_is_unattributed(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();
        CostLineItem::factory()->for($provider)->forPeriod(self::PERIOD)->usd(15.0)->create();

        $this->alertJob($business->id);

        Mail::assertSent(ReconciliationAlert::class);
    }

    public function test_no_alert_when_the_period_reconciles(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();

        ProviderResource::factory()->for($provider, 'costProvider')->attributedTo($project)->create();
        CostLineItem::factory()->for($provider)->forPeriod(self::PERIOD)->usd(15.0)
            ->attributedTo($project)->create();

        $this->alertJob($business->id);

        Mail::assertNothingSent();
    }

    public function test_no_alert_for_overhead_alone(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();
        CostLineItem::factory()->for($provider)->forPeriod(self::PERIOD)
            ->ofCategory(CostCategory::Support)->usd(7.0)->create();

        $this->alertJob($business->id);

        Mail::assertNothingSent();
    }

    public function test_alert_skips_a_missing_business(): void
    {
        Mail::fake();

        $this->alertJob(9999);

        Mail::assertNothingSent();
    }

    public function test_alert_renders_with_a_link_to_the_period(): void
    {
        $business = Business::factory()->create();
        $provider = CostProvider::factory()->for($business)->smtp2go()->create();
        ProviderResource::factory()->for($provider, 'costProvider')->create();
        CostLineItem::factory()->for($provider)->forPeriod(self::PERIOD)->usd(15.0)->create();

        $mail = new ReconciliationAlert(
            business: $business,
            summary: app(\App\Services\Billing\ReconciliationReporter::class)->summarize($business->id, self::PERIOD),
            unattributedResources: app(\App\Services\Billing\ReconciliationReporter::class)->unattributedResources($business->id),
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('August 2026', $rendered);
        $this->assertStringContainsString('$15.00 USD', $rendered);
        $this->assertStringContainsString(route('admin.billing.reconciliation.index', ['period' => self::PERIOD]), $rendered);
    }

    public function test_open_periods_include_the_previous_month_only_during_the_grace_window(): void
    {
        $this->assertSame(['2026-08', '2026-07'], BillingPeriod::openPeriods(Carbon::parse('2026-08-03')));
        $this->assertSame(['2026-08'], BillingPeriod::openPeriods(Carbon::parse('2026-08-20')));
    }

    private function billingJob(int $providerId): SyncProviderBillingJob
    {
        return new SyncProviderBillingJob($providerId, self::PERIOD);
    }

    private function alertJob(int $businessId): void
    {
        (new ReconciliationAlertJob($businessId, self::PERIOD))
            ->handle(app(\App\Services\Billing\ReconciliationReporter::class));
    }

    private function fakeCycle(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response($this->jsonFixture('smtp2go/email_cycle.json'))]);
    }
}
