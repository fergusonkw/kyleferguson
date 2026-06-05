<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

interface PaymentGatewayContract
{
    /**
     * Initiate a charge. Returns a gateway reference string on success.
     *
     * @param  array<string, mixed>  $payload
     */
    public function charge(float $amount, string $currency, array $payload): string;

    /**
     * Refund a previously completed charge.
     */
    public function refund(string $reference, float $amount): void;

    /**
     * Whether this gateway is configured and available for use.
     */
    public function isAvailable(): bool;
}
