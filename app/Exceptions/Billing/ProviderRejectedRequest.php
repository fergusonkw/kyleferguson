<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Billing\CostProvider;
use RuntimeException;

/**
 * A provider refused the request for a reason retrying will not fix — a
 * revoked key, a key without permission for the endpoint, an account that no
 * longer exists.
 *
 * Distinguished from a transient failure so the job can fail fast: retrying a
 * permission error three times only buries the provider's own explanation
 * under a MaxAttemptsExceededException, which tells the operator nothing.
 */
final class ProviderRejectedRequest extends RuntimeException
{
    public static function for(CostProvider $provider, int $status, string $reason): self
    {
        return new self(sprintf(
            '%s rejected the request (HTTP %d): %s',
            $provider->slug->label(),
            $status,
            trim($reason) !== '' ? trim($reason) : 'no reason given',
        ));
    }
}
