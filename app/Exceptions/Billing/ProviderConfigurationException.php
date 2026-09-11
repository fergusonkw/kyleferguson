<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Billing\CostProvider;
use RuntimeException;

/**
 * Thrown when a cost provider is missing configuration a sync depends on —
 * for providers priced by a flat operator-entered fee, a missing fee would
 * otherwise silently ingest a zero cost.
 */
final class ProviderConfigurationException extends RuntimeException
{
    public static function missing(CostProvider $provider, string $key): self
    {
        return new self(sprintf(
            'Cost provider [%s] is missing required configuration "%s".',
            $provider->display_name,
            $key,
        ));
    }

    public static function invalid(CostProvider $provider, string $key, string $reason): self
    {
        return new self(sprintf(
            'Cost provider [%s] has invalid configuration "%s": %s.',
            $provider->display_name,
            $key,
            $reason,
        ));
    }
}
