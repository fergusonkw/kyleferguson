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
     * USD breakdown for the given period across all line items from this
     * business's providers, split by where the attribution landed.
     *
     * @return array{do_total_usd: float, attributed_own_usd: float, attributed_other_usd: float, unattributed_usd: float}
     */
    public function costGap(Business $business, string $period): array
    {
        $base = CostLineItem::query()
            ->where('period', $period)
            ->whereHas('costProvider', fn ($q) => $q->where('business_id', $business->id));

        $doTotal = (float) (clone $base)->sum('usd_amount');

        $attributedOwn = (float) (clone $base)
            ->whereNotNull('project_id')
            ->whereHas('project.client', fn ($q) => $q->where('business_id', $business->id))
            ->sum('usd_amount');

        $attributedOther = (float) (clone $base)
            ->whereNotNull('project_id')
            ->whereHas('project.client', fn ($q) => $q->where('business_id', '!=', $business->id))
            ->sum('usd_amount');

        return [
            'do_total_usd' => $doTotal,
            'attributed_own_usd' => $attributedOwn,
            'attributed_other_usd' => $attributedOther,
            'unattributed_usd' => round($doTotal - $attributedOwn - $attributedOther, 2),
        ];
    }

    /**
     * Trailing 12-month costs attributed to this business's own projects.
     * Cross-business attributed costs are excluded so that managing a shared
     * DO account doesn't inflate another business's threshold metric.
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
            ->whereHas('project.client', fn ($q) => $q->where('business_id', $business->id))
            ->sum('usd_amount');

        return ['periods' => $periods, 'total_usd' => $total];
    }

    /**
     * Returns trailing-12mo cost versus the business's configured threshold.
     *
     * @return array{exceeded: bool, total_usd: float, threshold_usd: float|null, percentage: float|null}
     */
    public function trailingThresholdStatus(Business $business): array
    {
        $trailing = $this->trailing12MonthCosts($business);
        $threshold = $business->trailing_12mo_threshold_usd;

        return [
            'exceeded' => $threshold !== null && $trailing['total_usd'] > $threshold,
            'total_usd' => $trailing['total_usd'],
            'threshold_usd' => $threshold,
            'percentage' => ($threshold !== null && $threshold > 0)
                ? round($trailing['total_usd'] / $threshold * 100, 1)
                : null,
        ];
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
