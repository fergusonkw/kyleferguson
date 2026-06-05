<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\ProviderResource;

final class ReconciliationReporter
{
    /**
     * Number of provider resources with no project attribution (sitting in
     * the DO Default Project or in a project not mapped to a client).
     */
    public function unattributedResourceCount(Business $business): int
    {
        return ProviderResource::query()
            ->whereNull('project_id')
            ->whereHas('costProvider', fn ($q) => $q->where('business_id', $business->id))
            ->count();
    }

    /**
     * USD total billed by DO in the given period vs. sum of client-attributed
     * cost line items. Returns both figures so the caller can compute the gap.
     *
     * @return array{do_total_usd: float, attributed_usd: float, gap_usd: float}
     */
    public function costGap(Business $business, string $period): array
    {
        $base = CostLineItem::query()
            ->where('period', $period)
            ->whereHas('costProvider', fn ($q) => $q->where('business_id', $business->id));

        $doTotal = (float) (clone $base)->sum('usd_amount');
        $attributed = (float) (clone $base)->whereNotNull('project_id')->sum('usd_amount');

        return [
            'do_total_usd' => $doTotal,
            'attributed_usd' => $attributed,
            'gap_usd' => $doTotal - $attributed,
        ];
    }

    /**
     * Trailing 12-month revenue per business (sum of attributed USD costs as a
     * proxy for revenue; Phase 3 will use invoice totals instead).
     *
     * @return array{periods: list<string>, total_usd: float}
     */
    public function trailing12MonthCosts(Business $business): array
    {
        $periods = $this->trailing12Periods();

        $total = (float) CostLineItem::query()
            ->whereIn('period', $periods)
            ->whereNotNull('project_id')
            ->whereHas('costProvider', fn ($q) => $q->where('business_id', $business->id))
            ->sum('usd_amount');

        return ['periods' => $periods, 'total_usd' => $total];
    }

    /**
     * @return list<string>
     */
    private function trailing12Periods(): array
    {
        $periods = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $periods[] = $cursor->format('Y-m');
            $cursor->subMonthNoOverflow();
        }

        return array_reverse($periods);
    }
}
