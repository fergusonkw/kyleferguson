<?php

declare(strict_types=1);

namespace App\Services\Billing\Pdf;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelPdf\Drivers\CloudflareDriver;
use Spatie\LaravelPdf\Exceptions\CouldNotGeneratePdf;
use Spatie\LaravelPdf\PdfOptions;
use Throwable;

/**
 * laravel-pdf's Cloudflare driver, made patient with Cloudflare's rate limit.
 *
 * The free plan allows one Browser Run request every ten seconds, so sending
 * two invoices back to back — or downloading one and then sending it — would
 * fail the second render outright. A 429 is a "not yet", not a "no": this
 * waits as long as Cloudflare asks (ten seconds when it does not say) and
 * tries again, a couple of times, before giving up.
 *
 * Only rate limiting is retried. A bad token or a malformed request will not
 * improve by waiting, and neither will the daily browser-time allowance once
 * it is spent.
 */
final class RetryingCloudflareDriver extends CloudflareDriver
{
    public const ATTEMPTS = 3;

    public const DEFAULT_WAIT_SECONDS = 10;

    /**
     * Upper bound on any single wait, so a large Retry-After cannot hold a
     * web request open for longer than a person would sit through.
     */
    public const MAX_WAIT_SECONDS = 15;

    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        $response = Http::withToken($this->apiToken)
            ->retry(
                self::ATTEMPTS,
                fn (int $attempt, Throwable $exception): int => $this->waitMilliseconds($exception),
                fn (Throwable $exception): bool => $this->isRateLimited($exception),
                throw: false,
            )
            ->post($this->endpoint(), $this->buildRequestBody($html, $headerHtml, $footerHtml, $options));

        if (! $response->successful()) {
            throw CouldNotGeneratePdf::cloudflareApiError($response->body());
        }

        return $response->body();
    }

    private function isRateLimited(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || $exception->response->status() !== 429) {
            return false;
        }

        return ! str_contains($exception->response->body(), 'time limit exceeded');
    }

    private function waitMilliseconds(Throwable $exception): int
    {
        $retryAfter = $exception instanceof RequestException
            ? $exception->response->header('Retry-After')
            : '';

        $seconds = is_numeric($retryAfter) && (int) $retryAfter > 0
            ? (int) $retryAfter
            : self::DEFAULT_WAIT_SECONDS;

        return min($seconds, self::MAX_WAIT_SECONDS) * 1000;
    }
}
