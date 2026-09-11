<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

/**
 * Thrown when a conversion rate cannot be resolved for a currency pair and
 * period. Invoice generation must fail loudly rather than silently guess a
 * rate — a wrong rate produces a wrong invoice.
 */
final class FxRateUnavailableException extends RuntimeException
{
    public static function forPair(string $from, string $to, string $period, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('No FX rate available for %s→%s in %s: %s', $from, $to, $period, $reason),
            0,
            $previous,
        );
    }
}
