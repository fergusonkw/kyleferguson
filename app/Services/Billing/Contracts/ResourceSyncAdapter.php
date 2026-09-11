<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\Billing\CostProvider;

/**
 * Implemented by providers that expose a project / resource inventory
 * (DigitalOcean). Providers whose whole account is a single billable
 * subscription do not implement this.
 */
interface ResourceSyncAdapter
{
    /**
     * Synchronize the provider's projects and resources into the local store.
     * Returns the number of resources observed during the sync.
     */
    public function syncProjectsAndResources(CostProvider $provider): int;
}
