<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\PaymentGatewayContract;
use RuntimeException;

/**
 * Placeholder for future Interac Online integration.
 * isAvailable() returns false until credentials are configured.
 */
final class InteracGateway implements PaymentGatewayContract
{
    public function charge(float $amount, string $currency, array $payload): string
    {
        throw new RuntimeException('Interac gateway is not yet implemented.');
    }

    public function refund(string $reference, float $amount): void
    {
        throw new RuntimeException('Interac gateway is not yet implemented.');
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
