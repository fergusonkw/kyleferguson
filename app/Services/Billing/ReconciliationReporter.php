<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\CostProviderSlug;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\Dto\ReconciliationSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Answers "does what I was charged match what I can bill for?" for a business
 * and period, and surfaces the costs that cannot yet be answered for.
 */
final class ReconciliationReporter
{
    private const BASIS = 'COALESCE(SUM(usd_amount + usd_tax), 0)';

    public function summarize(int $businessId, string $period): ReconciliationSummary
    {
        $nonAttributable = CostCategory::nonAttributableValues();

        $attributed = $this->sumBasis(
            $businessId,
            $period,
            fn (Builder $q): Builder => $q->whereNotNull('project_id')->whereNotIn('category', $nonAttributable),
        );

        $unattributed = $this->sumBasis(
            $businessId,
            $period,
            fn (Builder $q): Builder => $q->whereNull('project_id')->whereNotIn('category', $nonAttributable),
        );

        $overhead = $this->sumBasis(
            $businessId,
            $period,
            fn (Builder $q): Builder => $q->whereIn('category', $nonAttributable),
        );

        [$reported, $gap] = $this->resolveCostGap($businessId, $period);

        return new ReconciliationSummary(
            period: $period,
            attributedCost: $attributed,
            unattributedCost: $unattributed,
            overheadCost: $overhead,
            providerReportedTotal: $reported,
            costGap: $gap,
            unattributedResourceCount: $this->unattributedResources($businessId)->count(),
            lineItemCount: $this->lineQuery($businessId, $period)->count(),
        );
    }

    /**
     * Cost rolled up per project, largest first. Keyed by project id.
     *
     * @return SupportCollection<int, array{project_id: int, project_name: string, client_name: string, cost: float}>
     */
    public function costByProject(int $businessId, string $period): SupportCollection
    {
        return $this->lineQuery($businessId, $period)
            ->whereNotNull('project_id')
            ->with(['project.client'])
            ->get()
            ->groupBy('project_id')
            ->map(function (Collection $lines): array {
                /** @var CostLineItem $first */
                $first = $lines->first();

                return [
                    'project_id' => (int) $first->project_id,
                    'project_name' => $first->project?->name ?? 'Unknown project',
                    'client_name' => $first->project?->client->name ?? 'Unknown client',
                    'cost' => $this->sumLines($lines),
                ];
            })
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * Cost rolled up per client, largest first.
     *
     * @return SupportCollection<int, array{client_id: int, client_name: string, cost: float}>
     */
    public function costByClient(int $businessId, string $period): SupportCollection
    {
        return $this->lineQuery($businessId, $period)
            ->whereNotNull('project_id')
            ->with(['project.client'])
            ->get()
            ->filter(fn (CostLineItem $line): bool => $line->project?->client !== null)
            ->groupBy(fn (CostLineItem $line): int => (int) $line->project->client_id)
            ->map(function (Collection $lines): array {
                /** @var CostLineItem $first */
                $first = $lines->first();

                return [
                    'client_id' => (int) $first->project->client_id,
                    'client_name' => $first->project->client->name,
                    'cost' => $this->sumLines($lines),
                ];
            })
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * Resources belonging to this business that no project claims — a stray DO
     * droplet, or an SMTP2GO account waiting to be pointed at a project.
     *
     * @return Collection<int, ProviderResource>
     */
    public function unattributedResources(int $businessId): Collection
    {
        return ProviderResource::query()
            ->whereNull('project_id')
            ->whereHas('costProvider', fn (Builder $q): Builder => $q->where('business_id', $businessId))
            ->with('costProvider')
            ->orderBy('resource_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Rolling cost over the trailing months, oldest period first. Stands in for
     * the trailing-12-month revenue gauge until invoices exist (Phase 3).
     *
     * @return SupportCollection<int, array{period: string, cost: float}>
     */
    public function trailingCost(int $businessId, int $months = 12, ?Carbon $endingAt = null): SupportCollection
    {
        $cursor = ($endingAt ?? now())->copy()->startOfMonth()->subMonths($months - 1);
        $periods = [];

        for ($i = 0; $i < $months; $i++) {
            $periods[] = $cursor->copy()->addMonths($i)->format('Y-m');
        }

        $totals = CostLineItem::query()
            ->forBusiness($businessId)
            ->whereIn('period', $periods)
            ->selectRaw('period, '.self::BASIS.' as basis')
            ->groupBy('period')
            ->pluck('basis', 'period');

        return collect($periods)->map(fn (string $period): array => [
            'period' => $period,
            'cost' => round((float) ($totals[$period] ?? 0), 4),
        ]);
    }

    /**
     * The gap is only computable where a provider independently reports what it
     * billed. With none connected, both figures are null and the UI shows "—"
     * rather than a zero that looks like a clean reconciliation.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function resolveCostGap(int $businessId, string $period): array
    {
        $slugs = array_values(array_filter(
            CostProviderSlug::cases(),
            fn (CostProviderSlug $slug): bool => $slug->reportsAuthoritativeTotal(),
        ));

        $providerIds = CostProvider::query()
            ->where('business_id', $businessId)
            ->whereIn('slug', array_map(fn (CostProviderSlug $s): string => $s->value, $slugs))
            ->pluck('id');

        if ($providerIds->isEmpty()) {
            return [null, null];
        }

        $reported = (float) CostLineItem::query()
            ->whereIn('cost_provider_id', $providerIds)
            ->forPeriod($period)
            ->selectRaw(self::BASIS.' as basis')
            ->value('basis');

        $accounted = (float) CostLineItem::query()
            ->whereIn('cost_provider_id', $providerIds)
            ->forPeriod($period)
            ->whereNotNull('attributed_at')
            ->selectRaw(self::BASIS.' as basis')
            ->value('basis');

        return [round($reported, 4), round($reported - $accounted, 4)];
    }

    /**
     * @param  callable(Builder<CostLineItem>): Builder<CostLineItem>  $filter
     */
    private function sumBasis(int $businessId, string $period, callable $filter): float
    {
        $query = $this->lineQuery($businessId, $period);

        return round((float) $filter($query)->selectRaw(self::BASIS.' as basis')->value('basis'), 4);
    }

    /**
     * @param  Collection<int, CostLineItem>  $lines
     */
    private function sumLines(Collection $lines): float
    {
        return round($lines->sum(fn (CostLineItem $line): float => (float) $line->usdCostBasis()), 4);
    }

    /**
     * @return Builder<CostLineItem>
     */
    private function lineQuery(int $businessId, string $period): Builder
    {
        return CostLineItem::query()
            ->forBusiness($businessId)
            ->forPeriod($period);
    }
}
