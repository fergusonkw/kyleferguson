<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\Billing\CostProvider;

/**
 * Implemented by providers whose costs can be ingested for a billing period.
 * Implementations must be idempotent: re-running for an unchanged period
 * updates nothing and creates no duplicate line items.
 */
interface BillingSyncAdapter
{
    /**
     * Ingest costs for one period (`YYYY-MM`), storing the raw provider payload
     * and deriving `cost_line_items`. Returns the number of line items written
     * or refreshed.
     */
    public function syncBilling(CostProvider $provider, string $period): int;
}
