<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/**
 * Reconciliation tiles for one business and period. All money is the USD cost
 * basis (provider amount plus its non-recoverable tax).
 */
final readonly class ReconciliationSummary
{
    public function __construct(
        public string $period,
        public float $attributedCost = 0.0,
        public float $unattributedCost = 0.0,
        public float $overheadCost = 0.0,
        public ?float $providerReportedTotal = null,
        public ?float $costGap = null,
        public int $unattributedResourceCount = 0,
        public int $lineItemCount = 0,

        /**
         * Attributed cost no live invoice is billing. Distinct from
         * unattributed: we know whose cost it is, nobody has been charged.
         */
        public float $uninvoicedCost = 0.0,
    ) {}

    public function totalIngestedCost(): float
    {
        return round($this->attributedCost + $this->unattributedCost + $this->overheadCost, 4);
    }

    /**
     * Whether the cost gap could be computed at all. It requires a connected
     * provider that independently reports what it billed.
     */
    public function hasCostGap(): bool
    {
        return $this->costGap !== null;
    }

    public function needsAttention(): bool
    {
        return $this->unattributedResourceCount > 0
            || $this->unattributedCost > 0
            || ($this->costGap !== null && abs($this->costGap) >= 0.01);
    }
}
