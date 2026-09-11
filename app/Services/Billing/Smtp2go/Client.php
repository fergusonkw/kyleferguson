<?php

declare(strict_types=1);

namespace App\Services\Billing\Smtp2go;

use App\Enums\Billing\Smtp2goRegion;
use App\Exceptions\Billing\ProviderRejectedRequest;
use App\Models\Billing\CostProvider;
use App\Services\Billing\Smtp2go\Dto\Smtp2goCycle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SMTP2GO stats API wrapper. Every endpoint is a POST, authenticated by the
 * `X-Smtp2go-Api-Key` header, served from a region-specific host.
 */
final class Client
{
    public function forProvider(CostProvider $provider): PendingRequest
    {
        $apiKey = (string) ($provider->credentials['api_key'] ?? '');

        if ($apiKey === '') {
            throw new RuntimeException("CostProvider {$provider->id} has no SMTP2GO API key configured.");
        }

        return Http::baseUrl($this->baseUrlFor($provider))
            ->withHeaders(['X-Smtp2go-Api-Key' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            // `throw: false` so a failed response comes back to us rather than
            // being turned into a RequestException with its body truncated —
            // SMTP2GO's explanation is in that body and is the whole point.
            ->retry(3, 250, fn ($e): bool => $e instanceof ConnectionException, throw: false);
    }

    public function validateCredentials(CostProvider $provider): bool
    {
        try {
            $this->fetchEmailCycleData($provider);

            return true;
        } catch (ConnectionException|RequestException|RuntimeException) {
            return false;
        }
    }

    /**
     * Why the credentials were refused, for showing the operator. Null when
     * they are fine or the failure was transient rather than a rejection.
     */
    public function credentialFailureReason(CostProvider $provider): ?string
    {
        try {
            $this->fetchEmailCycleData($provider);

            return null;
        } catch (ProviderRejectedRequest $e) {
            return $e->getMessage();
        } catch (ConnectionException|RequestException|RuntimeException) {
            return null;
        }
    }

    /**
     * The account's current billing-cycle usage.
     */
    public function emailCycle(CostProvider $provider): Smtp2goCycle
    {
        return Smtp2goCycle::fromApiPayload($this->fetchEmailCycleData($provider));
    }

    /**
     * The raw `data` object, kept verbatim for `provider_billing_payloads`.
     *
     * @return array<string, mixed>
     */
    public function fetchEmailCycleData(CostProvider $provider): array
    {
        $response = $this->forProvider($provider)->post('/stats/email_cycle');

        // SMTP2GO explains itself in `data.error`, on both 200 and 4xx replies.
        // Read that before calling throw(), which would replace the useful
        // message with a truncated generic one.
        $error = $response->json('data.error');

        if ($error !== null) {
            throw ProviderRejectedRequest::for($provider, $response->status(), (string) $error);
        }

        // A 4xx with no structured reason is still the provider's decision, not
        // a blip — surface the body and let the caller fail fast. 429 is the
        // exception: it is a "later", so it stays retryable.
        if ($response->clientError() && $response->status() !== 429) {
            throw ProviderRejectedRequest::for($provider, $response->status(), $response->body());
        }

        $response->throw();

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    private function baseUrlFor(CostProvider $provider): string
    {
        $region = Smtp2goRegion::tryFrom((string) $provider->config('region', Smtp2goRegion::Global->value))
            ?? Smtp2goRegion::Global;

        return $region->baseUrl();
    }
}
