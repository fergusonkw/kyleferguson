<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean\Dto;

use InvalidArgumentException;

final readonly class DoResource
{
    public function __construct(
        public string $urn,
        public string $type,
        public string $id,
        public string $projectUuid,
        public ?string $name,
        public ?string $status,
    ) {}

    /**
     * Parse a DigitalOcean URN ("do:droplet:12345") into its parts.
     *
     * @return array{type: string, id: string}
     */
    public static function parseUrn(string $urn): array
    {
        $parts = explode(':', $urn, 3);

        if (count($parts) !== 3 || $parts[0] !== 'do') {
            throw new InvalidArgumentException("Invalid DigitalOcean URN: {$urn}");
        }

        return ['type' => $parts[1], 'id' => $parts[2]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload, string $projectUuid): self
    {
        $urn = (string) $payload['urn'];
        $parsed = self::parseUrn($urn);

        return new self(
            urn: $urn,
            type: $parsed['type'],
            id: $parsed['id'],
            projectUuid: $projectUuid,
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
        );
    }
}
