<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\PaymentGatewayContract;
use RuntimeException;

/**
 * Placeholder for future Stripe integration.
 * isAvailable() returns false until credentials and the Stripe SDK are configured.
 */
final class StripeGateway implements PaymentGatewayContract
{
    public function charge(float $amount, string $currency, array $payload): string
    {
        throw new RuntimeException('Stripe gateway is not yet implemented.');
    }

    public function refund(string $reference, float $amount): void
    {
        throw new RuntimeException('Stripe gateway is not yet implemented.');
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
