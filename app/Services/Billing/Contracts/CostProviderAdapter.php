<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\Billing\CostProvider;

interface CostProviderAdapter
{
    /**
     * Confirm the provider's credentials are valid (read-only check).
     */
    public function validateCredentials(CostProvider $provider): bool;

    /**
     * Synchronize the provider's projects and resources into the local store.
     * Returns the number of resources observed during the sync.
     */
    public function syncProjectsAndResources(CostProvider $provider): int;
}
