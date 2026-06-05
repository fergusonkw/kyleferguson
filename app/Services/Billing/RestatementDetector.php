<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;

final class RestatementDetector
{
    /**
     * Compare the two most recent payloads for a provider+period, then apply
     * adjustment lines to any open draft invoices for affected clients.
     *
     * Returns the number of adjustment lines written.
     */
    public function detectAndApply(CostProvider $provider, string $period): int
    {
        $payloads = ProviderBillingPayload::query()
            ->where('cost_provider_id', $provider->id)
            ->where('period', $period)
            ->orderByDesc('created_at')
            ->limit(2)
            ->get();

        if ($payloads->count() < 2) {
            return 0;
        }

        [$newPayload, $oldPayload] = [$payloads->get(0), $payloads->get(1)];

        $oldAmounts = CostLineItem::query()
            ->where('source_payload_id', $oldPayload->id)
            ->whereNotNull('project_id')
            ->selectRaw('project_id, SUM(usd_amount) as total_usd')
            ->groupBy('project_id')
            ->pluck('total_usd', 'project_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $newAmounts = CostLineItem::query()
            ->where('source_payload_id', $newPayload->id)
            ->whereNotNull('project_id')
            ->selectRaw('project_id, SUM(usd_amount) as total_usd')
            ->groupBy('project_id')
            ->pluck('total_usd', 'project_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $allProjectIds = array_unique(array_merge(
            array_keys($oldAmounts),
            array_keys($newAmounts),
        ));

        $adjustmentsApplied = 0;

        foreach ($allProjectIds as $projectId) {
            $oldAmount = $oldAmounts[$projectId] ?? 0.0;
            $newAmount = $newAmounts[$projectId] ?? 0.0;
            $deltaUsd = $newAmount - $oldAmount;

            if (abs($deltaUsd) < 0.001) {
                continue;
            }

            $project = Project::find($projectId);
            if ($project === null) {
                continue;
            }

            $draft = Invoice::query()
                ->where('client_id', $project->client_id)
                ->where('status', InvoiceStatus::Draft)
                ->orderBy('created_at')
                ->first();

            if ($draft === null) {
                continue;
            }

            $sourceRef = 'restatement:'.$oldPayload->id.':'.$newPayload->id.':project:'.$projectId;

            $alreadyApplied = InvoiceLine::query()
                ->where('invoice_id', $draft->id)
                ->where('source_reference', $sourceRef)
                ->exists();

            if ($alreadyApplied) {
                continue;
            }

            $deltaInIssueCurrency = round($deltaUsd * $draft->fx_rate_snapshot, 2);
            $maxOrder = $draft->lines()->max('display_order') ?? -1;

            InvoiceLine::create([
                'invoice_id' => $draft->id,
                'project_id' => $projectId,
                'label' => 'DO Restatement — '.$project->name.' ('.$period.')',
                'line_type' => InvoiceLineType::Adjustment,
                'amount' => $deltaInIssueCurrency,
                'source_reference' => $sourceRef,
                'display_order' => $maxOrder + 1,
            ]);

            $this->recalculateTotals($draft);
            $adjustmentsApplied++;
        }

        return $adjustmentsApplied;
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get();

        $subtotal = $lines->whereNotIn('line_type', [
            InvoiceLineType::Tax,
            InvoiceLineType::Discount,
            InvoiceLineType::Credit,
        ])->sum('amount');

        $tax = $lines->where('line_type', InvoiceLineType::Tax)->sum('amount');
        $discounts = $lines->whereIn('line_type', [
            InvoiceLineType::Discount,
            InvoiceLineType::Credit,
        ])->sum('amount');

        $invoice->update([
            'subtotal' => round((float) $subtotal, 2),
            'total' => round((float) $subtotal + (float) $tax - (float) $discounts, 2),
        ]);
    }
}
