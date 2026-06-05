<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean;

use App\Models\Billing\CostProvider;
use App\Services\Billing\DigitalOcean\Dto\DoProject;
use App\Services\Billing\DigitalOcean\Dto\DoResource;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Client
{
    private const BASE_URL = 'https://api.digitalocean.com/v2';

    private const PER_PAGE = 200;

    public function forProvider(CostProvider $provider): PendingRequest
    {
        $token = (string) ($provider->credentials['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException("CostProvider {$provider->id} has no DigitalOcean token configured.");
        }

        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 250, fn ($e) => $e instanceof ConnectionException);
    }

    public function validateToken(CostProvider $provider): bool
    {
        try {
            $response = $this->forProvider($provider)->get('/account');

            return $response->successful();
        } catch (ConnectionException|RequestException) {
            return false;
        }
    }

    /**
     * @return Generator<int, DoProject>
     */
    public function listProjects(CostProvider $provider): Generator
    {
        foreach ($this->paginate($provider, '/projects', 'projects') as $payload) {
            yield DoProject::fromApiPayload($payload);
        }
    }

    /**
     * @return Generator<int, DoResource>
     */
    public function listProjectResources(CostProvider $provider, string $projectUuid): Generator
    {
        $path = "/projects/{$projectUuid}/resources";

        foreach ($this->paginate($provider, $path, 'resources') as $payload) {
            yield DoResource::fromApiPayload($payload, $projectUuid);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function paginate(CostProvider $provider, string $path, string $itemsKey): Generator
    {
        $page = 1;

        while (true) {
            $response = $this->forProvider($provider)->get($path, [
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ]);

            $response->throw();
            $body = $response->json();

            foreach ($body[$itemsKey] ?? [] as $item) {
                yield $item;
            }

            $nextUrl = $body['links']['pages']['next'] ?? null;
            if (! $nextUrl) {
                break;
            }

            $page++;
        }
    }
}
