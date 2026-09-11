<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Role as RoleEnum;
use App\Exceptions\Billing\ProviderRejectedRequest;
use App\Jobs\Billing\SyncProviderBillingJob;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Services\Billing\ProviderAdapterRegistry;
use App\Services\Billing\Smtp2go\Client as Smtp2goClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * How a provider's refusal reaches the operator.
 *
 * A real SMTP2GO key without stats permission surfaced as
 * "MaxAttemptsExceededException" in failed_jobs, with the provider's actual
 * explanation truncated away — three retries of a permission error, and
 * nothing to act on at the end.
 */
final class CheckpointD8ProviderErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    /** The message SMTP2GO actually returns for a key lacking permission. */
    private const REAL_ERROR = 'This API key does not have the appropriate permissions to access this endpoint. Please check the permissions assigned to this API key in the SMTP2GO web app.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_permission_error_surfaces_in_full_not_truncated(): void
    {
        $this->fakeRejection(400, self::REAL_ERROR);
        $provider = CostProvider::factory()->smtp2go()->create();

        try {
            app(Smtp2goClient::class)->fetchEmailCycleData($provider);
            $this->fail('Expected ProviderRejectedRequest');
        } catch (ProviderRejectedRequest $e) {
            // The whole sentence, including what the operator has to go and fix.
            $this->assertStringContainsString(self::REAL_ERROR, $e->getMessage());
            $this->assertStringContainsString('SMTP2GO rejected the request', $e->getMessage());
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringNotContainsString('truncated', $e->getMessage());
        }
    }

    public function test_the_providers_record_keeps_the_full_reason(): void
    {
        $this->fakeRejection(400, self::REAL_ERROR);
        $provider = CostProvider::factory()->smtp2go()->create();

        try {
            app(ProviderAdapterRegistry::class)
                ->billingSyncFor($provider->slug)
                ->syncBilling($provider, self::PERIOD);
        } catch (Throwable) {
            // expected
        }

        $this->assertStringContainsString(self::REAL_ERROR, (string) $provider->fresh()->last_sync_error);
    }

    public function test_a_rejection_fails_the_job_immediately_rather_than_retrying(): void
    {
        $this->fakeRejection(400, self::REAL_ERROR);
        $provider = CostProvider::factory()->smtp2go()->create();

        $job = new SyncProviderBillingJob($provider->id, self::PERIOD);
        $job->handle(app(ProviderAdapterRegistry::class));

        // handle() returns normally after calling fail(), so the queue does not
        // re-attempt and the real reason is what lands in failed_jobs.
        $this->assertSame(0, CostLineItem::count());
    }

    public function test_a_rejection_without_a_structured_reason_still_fails_fast(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response('Forbidden', 403)]);
        $provider = CostProvider::factory()->smtp2go()->create();

        try {
            app(Smtp2goClient::class)->fetchEmailCycleData($provider);
            $this->fail('Expected ProviderRejectedRequest');
        } catch (ProviderRejectedRequest $e) {
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringContainsString('Forbidden', $e->getMessage());
        }
    }

    public function test_rate_limiting_stays_retryable(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response('slow down', 429)]);
        $provider = CostProvider::factory()->smtp2go()->create();

        try {
            app(Smtp2goClient::class)->fetchEmailCycleData($provider);
            $this->fail('Expected an exception');
        } catch (Throwable $e) {
            // A 429 is a "later", not a refusal, so it must not be treated as
            // permanent — the job should be allowed to retry it.
            $this->assertNotInstanceOf(ProviderRejectedRequest::class, $e);
        }
    }

    public function test_a_server_error_stays_retryable(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response('boom', 500)]);
        $provider = CostProvider::factory()->smtp2go()->create();

        try {
            app(Smtp2goClient::class)->fetchEmailCycleData($provider);
            $this->fail('Expected an exception');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(ProviderRejectedRequest::class, $e);
        }
    }

    public function test_credential_validation_reports_why_it_failed(): void
    {
        $this->fakeRejection(400, self::REAL_ERROR);
        $provider = CostProvider::factory()->smtp2go()->create();
        $client = app(Smtp2goClient::class);

        $this->assertFalse($client->validateCredentials($provider));
        $this->assertStringContainsString(self::REAL_ERROR, (string) $client->credentialFailureReason($provider));
    }

    public function test_a_working_key_reports_no_failure_reason(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response($this->jsonFixture('smtp2go/email_cycle.json'))]);
        $provider = CostProvider::factory()->smtp2go()->create();
        $client = app(Smtp2goClient::class);

        $this->assertTrue($client->validateCredentials($provider));
        $this->assertNull($client->credentialFailureReason($provider));
    }

    public function test_the_queue_monitor_endpoints_live_under_the_admin_prefix(): void
    {
        // The page hardcoded these without /admin, so every panel 404'd and the
        // tables sat on their error state.
        $this->assertSame('/admin/queue-monitor/jobs', parse_url(route('admin.queue-monitor.jobs'), PHP_URL_PATH));
        $this->assertSame('/admin/queue-monitor/failed-jobs', parse_url(route('admin.queue-monitor.failed-jobs'), PHP_URL_PATH));
        $this->assertSame('/admin/queue-monitor/queues', parse_url(route('admin.queue-monitor.queues'), PHP_URL_PATH));
    }

    public function test_the_queue_monitor_page_builds_its_urls_from_named_routes(): void
    {
        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.queue-monitor.index'))
            ->assertOk();

        $response->assertSee(route('admin.queue-monitor.jobs'), false);
        $response->assertSee(route('admin.queue-monitor.failed-jobs'), false);
        $response->assertDontSee('fetch(\'/queue-monitor/', false);
    }

    public function test_the_queue_monitor_still_requires_its_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.queue-monitor.index'))
            ->assertForbidden();
    }

    private function fakeRejection(int $status, string $error): void
    {
        Http::fake([
            '*/stats/email_cycle' => Http::response([
                'request_id' => '59b80cb0-9185-4927-8eaf-7c9358e1f4b2',
                'data' => ['error' => $error, 'error_code' => 'E_ApiResponseCodes.ENDPOINT_PERMISSION_DENIED'],
            ], $status),
        ]);
    }
}
