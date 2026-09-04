<?php

declare(strict_types=1);

namespace App\Services\Billing\Smtp2go;

use App\Enums\Billing\Smtp2goRegion;
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
            ->retry(3, 250, fn ($e): bool => $e instanceof ConnectionException);
    }

    public function validateCredentials(CostProvider $provider): bool
    {
        try {
            $response = $this->forProvider($provider)->post('/stats/email_cycle');

            return $response->successful() && $response->json('data.error') === null;
        } catch (ConnectionException|RequestException|RuntimeException) {
            return false;
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
        $response->throw();

        $error = $response->json('data.error');
        if ($error !== null) {
            throw new RuntimeException("SMTP2GO rejected the request: {$error}");
        }

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
