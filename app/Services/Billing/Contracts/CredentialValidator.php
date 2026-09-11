<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\Billing\CostProvider;

interface CredentialValidator
{
    /**
     * Confirm the provider's credentials are valid (read-only check).
     */
    public function validateCredentials(CostProvider $provider): bool;
}
