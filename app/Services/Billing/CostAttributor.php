<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\ResourceAssignment;
use Illuminate\Support\Carbon;

final class CostAttributor
{
    /**
     * Assign project_id to all unattributed cost_line_items for the given
     * provider and period by looking up the active resource_assignment at the
     * start of that period.
     *
     * Returns the number of line items that were attributed.
     */
    public function attributeForProvider(CostProvider $provider, string $period): int
    {
        $periodStart = Carbon::parse($period.'-01')->startOfMonth();

        $unattributed = CostLineItem::query()
            ->where('cost_provider_id', $provider->id)
            ->where('period', $period)
            ->whereNull('project_id')
            ->whereNotNull('provider_resource_id')
            ->get();

        if ($unattributed->isEmpty()) {
            return 0;
        }

        $resourceIds = $unattributed->pluck('provider_resource_id')->unique()->all();

        $assignmentMap = ResourceAssignment::query()
            ->whereIn('provider_resource_id', $resourceIds)
            ->whereNotNull('project_id')
            ->where('observed_from', '<=', $periodStart)
            ->where(fn ($q) => $q->whereNull('observed_to')->orWhere('observed_to', '>', $periodStart))
            ->pluck('project_id', 'provider_resource_id')
            ->all();

        $attributed = 0;

        foreach ($unattributed as $item) {
            $projectId = $assignmentMap[$item->provider_resource_id] ?? null;

            if ($projectId !== null) {
                $item->update(['project_id' => $projectId]);
                $attributed++;
            }
        }

        return $attributed;
    }

    /**
     * Returns counts useful for reconciliation reporting.
     *
     * @return array{unattributed_items: int, unattributed_usd: float, attributed_items: int, attributed_usd: float}
     */
    public function reconciliationSummary(CostProvider $provider, string $period): array
    {
        $base = CostLineItem::query()
            ->where('cost_provider_id', $provider->id)
            ->where('period', $period);

        $unattributed = (clone $base)->whereNull('project_id');
        $attributed = (clone $base)->whereNotNull('project_id');

        return [
            'unattributed_items' => (int) $unattributed->count(),
            'unattributed_usd' => (float) $unattributed->sum('usd_amount'),
            'attributed_items' => (int) $attributed->count(),
            'attributed_usd' => (float) $attributed->sum('usd_amount'),
        ];
    }
}
