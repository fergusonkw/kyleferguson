<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\InvoicePdfRenderer;
use App\Services\Billing\Pdf\RetryingCloudflareDriver;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Spatie\LaravelPdf\Exceptions\CouldNotGeneratePdf;
use Tests\TestCase;

/**
 * Cloudflare's free plan renders one PDF every ten seconds. Sending two
 * invoices back to back must not fail the second — a 429 is waited out and
 * retried — while anything that waiting cannot fix still fails at once.
 */
final class CheckpointG4CloudflareRetryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/acct-123/browser-rendering/pdf';

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'laravel-pdf.driver' => 'cloudflare',
            'laravel-pdf.cloudflare' => ['api_token' => 'cf-test-token', 'account_id' => 'acct-123'],
        ]);

        Http::preventStrayRequests();
        Sleep::fake();

        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create(['billing_currency' => 'CAD']);
        $this->invoice = Invoice::factory()->for($business)->for($client)->withTotal(120.00)->create();
        InvoiceLine::factory()->for($this->invoice)->ofType(InvoiceLineType::Manual)->amount(120.00)->create();
    }

    public function test_the_cloudflare_driver_is_the_retrying_one(): void
    {
        $this->assertInstanceOf(RetryingCloudflareDriver::class, app('laravel-pdf.driver.cloudflare'));
    }

    public function test_a_successful_render_returns_the_pdf_without_waiting(): void
    {
        Http::fake([self::ENDPOINT => Http::response('%PDF-1.7 rendered', 200)]);

        $this->assertSame('%PDF-1.7 rendered', $this->render());

        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }

    public function test_the_request_carries_the_token_the_html_and_the_page_setup(): void
    {
        Http::fake([self::ENDPOINT => Http::response('%PDF-1.7 rendered', 200)]);

        $this->render();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer cf-test-token')
                && str_contains((string) $request['html'], $this->invoice->invoice_number)
                && $request['pdfOptions']['format'] === 'letter'
                && $request['pdfOptions']['margin']['top'] === '14mm'
                && $request['pdfOptions']['printBackground'] === true;
        });
    }

    public function test_a_rate_limited_render_waits_ten_seconds_and_retries(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['errors' => [['message' => 'Rate limit exceeded']]], 429)
            ->push('%PDF-1.7 rendered', 200)]);

        $this->assertSame('%PDF-1.7 rendered', $this->render());

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        $this->assertSleptSeconds(10);
    }

    public function test_cloudflares_retry_after_is_honoured(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => '4'])
            ->push('%PDF-1.7 rendered', 200)]);

        $this->render();

        $this->assertSleptSeconds(4);
    }

    public function test_a_long_retry_after_is_capped(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => '120'])
            ->push('%PDF-1.7 rendered', 200)]);

        $this->render();

        $this->assertSleptSeconds(RetryingCloudflareDriver::MAX_WAIT_SECONDS);
    }

    public function test_it_gives_up_after_three_attempts(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Rate limit exceeded', 429)]);

        $this->assertRenderFails('Rate limit exceeded');

        Http::assertSentCount(RetryingCloudflareDriver::ATTEMPTS);
        Sleep::assertSleptTimes(RetryingCloudflareDriver::ATTEMPTS - 1);
    }

    public function test_a_bad_token_fails_at_once(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['errors' => [['message' => 'Authentication error']]], 401)]);

        $this->assertRenderFails('Authentication error');

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_spent_daily_allowance_is_not_waited_on(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Browser time limit exceeded for today.', 429)]);

        $this->assertRenderFails('Browser time limit exceeded');

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    private function render(): string
    {
        return app(InvoicePdfRenderer::class)->pdf($this->invoice->fresh());
    }

    private function assertRenderFails(string $messageFragment): void
    {
        try {
            $this->render();
            $this->fail('Expected the render to fail.');
        } catch (CouldNotGeneratePdf $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    private function assertSleptSeconds(int $seconds): void
    {
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === $seconds);
    }
}
