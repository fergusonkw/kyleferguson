<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\Billing\CostProviderSlug;
use RuntimeException;

/**
 * Thrown when a cost provider is asked for a capability it does not implement —
 * e.g. billing ingestion for a provider whose adapter is not built yet.
 */
final class UnsupportedProviderCapability extends RuntimeException
{
    public static function for(CostProviderSlug $slug, string $capability): self
    {
        return new self(sprintf(
            'Cost provider [%s] does not support %s.',
            $slug->value,
            $capability,
        ));
    }
}
