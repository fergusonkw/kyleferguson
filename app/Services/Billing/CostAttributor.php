<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\CostLineItem;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\Dto\AttributionResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Resolves which project each ingested cost belongs to.
 *
 * Attribution reads the effective-dated `resource_assignments` history rather
 * than a resource's current project, so a resource that moved between projects
 * bills to wherever it lived during the period.
 */
final class CostAttributor
{
    /**
     * Attribute every line item for a business and period. Idempotent: lines
     * whose attribution is unchanged are not re-saved.
     */
    public function attribute(int $businessId, string $period): AttributionResult
    {
        $periodEnd = $this->periodEnd($period);
        $result = new AttributionResult;

        $lines = CostLineItem::query()
            ->forBusiness($businessId)
            ->forPeriod($period)
            ->get();

        foreach ($lines as $line) {
            $result = $this->attributeLine($line, $periodEnd, $result);
        }

        return $result;
    }

    private function attributeLine(CostLineItem $line, Carbon $periodEnd, AttributionResult $result): AttributionResult
    {
        if (! $line->category->isAttributable()) {
            return $result->withOverhead($this->apply($line, null));
        }

        if ($line->provider_resource_id === null) {
            return $result->withUnattributed($this->apply($line, null));
        }

        $projectId = $this->resolveProjectId($line->provider_resource_id, $periodEnd);
        $changed = $this->apply($line, $projectId);

        return $projectId === null
            ? $result->withUnattributed($changed)
            : $result->withAttributed($changed);
    }

    /**
     * The project a resource belonged to at period end.
     *
     * A resource whose assignment history begins *after* the period was simply
     * not tracked yet — it did not move — so its first known assignment is used
     * rather than leaving the cost permanently unattributed. A resource that
     * genuinely moved is still governed by the period-end rule.
     */
    private function resolveProjectId(int $providerResourceId, Carbon $periodEnd): ?int
    {
        $covering = ResourceAssignment::query()
            ->where('provider_resource_id', $providerResourceId)
            ->where('observed_from', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('observed_to')->orWhere('observed_to', '>=', $periodEnd))
            ->orderByDesc('observed_from')
            ->first();

        if ($covering !== null) {
            return $covering->project_id;
        }

        $anyEarlier = ResourceAssignment::query()
            ->where('provider_resource_id', $providerResourceId)
            ->where('observed_from', '<=', $periodEnd)
            ->exists();

        if ($anyEarlier) {
            return null;
        }

        return ResourceAssignment::query()
            ->where('provider_resource_id', $providerResourceId)
            ->orderBy('observed_from')
            ->first()?->project_id;
    }

    /**
     * Persist the resolved project, returning whether anything actually changed.
     */
    private function apply(CostLineItem $line, ?int $projectId): bool
    {
        $changed = $line->project_id !== $projectId || $line->attributed_at === null;

        if (! $changed) {
            return false;
        }

        $line->project_id = $projectId;
        $line->attributed_at = now();
        $line->save();

        return true;
    }

    private function periodEnd(string $period): Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("Billing period must be YYYY-MM, got [{$period}].");
        }

        return Carbon::createFromFormat('Y-m', $period)->endOfMonth();
    }
}
