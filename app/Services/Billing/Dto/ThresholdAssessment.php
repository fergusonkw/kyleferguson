<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

use App\Enums\Billing\ThresholdLevel;
use App\Enums\Billing\ThresholdTest;
use App\Models\Billing\LegalEntity;

/**
 * Where a legal entity — with its associates — stands against the GST/HST
 * small-supplier threshold on a given day. All money is CAD.
 */
final readonly class ThresholdAssessment
{
    /**
     * @param  list<array{label: string, start: string, end: string, total: string, current: bool}>  $quarters  oldest first; the last is the current quarter
     * @param  list<string>  $entityNames  the entity first, then its associates
     * @param  list<string>  $businessNames
     */
    public function __construct(
        public LegalEntity $entity,
        public ThresholdLevel $level,
        public string $threshold,
        public int $warningPercent,
        public array $quarters,
        public string $currentQuarterTotal,
        public string $fourQuarterTotal,
        public string $previousFourQuarterTotal,
        public ?ThresholdTest $exceededBy,
        public int $uncountedInvoiceCount,
        public array $entityNames,
        public array $businessNames,
    ) {}

    /**
     * Share of the threshold used by whichever test is closer to tripping.
     */
    public function percentUsed(): float
    {
        $largest = max((float) $this->fourQuarterTotal, (float) $this->currentQuarterTotal);

        return round($largest / (float) $this->threshold * 100, 1);
    }

    /**
     * What is left before the four-quarter test trips. Negative once it has.
     */
    public function headroom(): string
    {
        return bcsub($this->threshold, $this->fourQuarterTotal, 2);
    }

    public function warningAmount(): string
    {
        return bcdiv(bcmul($this->threshold, (string) $this->warningPercent, 2), '100', 2);
    }

    public function includesAssociates(): bool
    {
        return count($this->entityNames) > 1;
    }

    public function needsAttention(): bool
    {
        if ($this->level === ThresholdLevel::Registered) {
            return false;
        }

        return $this->level === ThresholdLevel::Approaching
            || $this->level === ThresholdLevel::Exceeded
            || $this->uncountedInvoiceCount > 0;
    }
}
