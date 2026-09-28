<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\Dto\UsageSnapshot;
use Illuminate\Database\Eloquent\Collection;

/**
 * Freezes what each billed service reported consuming onto the invoice.
 *
 * A metered service's usage keeps moving after the period it describes has
 * closed — an SMTP2GO cycle anchored mid-month is still counting when the
 * month is invoiced, and every sync rewrites it. An invoice that read those
 * figures live would therefore quietly restate itself after it was issued, so
 * each hosting line carries its own copy instead.
 *
 * Taken on the same schedule as {@see InvoiceSnapshotter}: re-taken whenever a
 * draft is rebuilt, and once more at approval, the point where the invoice
 * becomes a permanent record. Nothing here touches an amount.
 */
final class InvoiceUsageSnapshotter
{
    /**
     * Re-take the usage on every derived hosting line from the costs behind it.
     */
    public function capture(Invoice $invoice): void
    {
        $lines = $invoice->lines()
            ->where('line_type', InvoiceLineType::Hosting)
            ->whereNull('parent_id')
            ->whereNotNull('project_id')
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $costsByProject = CostLineItem::query()
            ->forPeriod($invoice->period)
            ->whereIn('project_id', $lines->pluck('project_id')->all())
            ->with('costProvider')
            ->get()
            ->groupBy('project_id');

        foreach ($lines as $line) {
            $this->write($line, $this->usageFor($costsByProject->get($line->project_id)));
        }
    }

    /**
     * @param  Collection<int, CostLineItem>|null  $costs
     * @return list<UsageSnapshot>
     */
    private function usageFor(?Collection $costs): array
    {
        if ($costs === null) {
            return [];
        }

        return $costs
            ->map(fn (CostLineItem $cost): ?UsageSnapshot => $cost->usage())
            ->filter()
            ->sortBy(fn (UsageSnapshot $usage): string => $usage->label)
            ->values()
            ->all();
    }

    /**
     * @param  list<UsageSnapshot>  $usage
     */
    private function write(InvoiceLine $line, array $usage): void
    {
        $metadata = $line->metadata ?? [];

        // A service that has stopped reporting usage — or been re-attributed
        // away from this project — leaves nothing behind, rather than leaving
        // the last reading on the line to be read as current.
        unset($metadata['usage'], $metadata['usage_captured_at']);

        if ($usage !== []) {
            $metadata['usage'] = array_map(
                static fn (UsageSnapshot $snapshot): array => $snapshot->toArray(),
                $usage,
            );
            $metadata['usage_captured_at'] = now()->toIso8601String();
        }

        $line->update(['metadata' => $metadata]);
    }
}
