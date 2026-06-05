<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean\Dto;

final readonly class DoProject
{
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $description,
        public bool $isDefault,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        return new self(
            uuid: (string) $payload['id'],
            name: (string) $payload['name'],
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            isDefault: (bool) ($payload['is_default'] ?? false),
        );
    }
}
