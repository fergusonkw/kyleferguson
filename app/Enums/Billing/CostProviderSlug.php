<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CostProviderSlug: string
{
    case DigitalOcean = 'digitalocean';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
        };
    }
}
