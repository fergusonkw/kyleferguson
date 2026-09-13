<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class Smtp2GoWebhookRequest extends FormRequest
{
    /**
     * SMTP2Go cannot sign its webhooks, but it can send an Authorization
     * header. The shared secret travels there — as a bearer token, or as the
     * password of basic auth — rather than in the URL, where it would be
     * written into every access log and Sentry breadcrumb along the way.
     */
    public function authorize(): bool
    {
        $secret = config('services.smtp2go.webhook_secret');
        $provided = $this->bearerToken() ?? $this->getPassword();

        return is_string($secret)
            && $secret !== ''
            && is_string($provided)
            && hash_equals($secret, $provided);
    }

    /**
     * Reports vary by event and by webhook settings, and one this application
     * cannot use is acknowledged rather than refused: a refusal makes SMTP2Go
     * retry it for two days.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * A 401 rather than a 403, so SMTP2Go's delivery log reads as a
     * credentials problem — and SMTP2Go keeps retrying while it is fixed.
     */
    protected function failedAuthorization(): void
    {
        throw new UnauthorizedHttpException('Bearer', 'Invalid webhook credentials.');
    }
}
