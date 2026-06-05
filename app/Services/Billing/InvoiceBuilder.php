<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\RecurringLineTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceBuilder
{
    /**
     * Federal GST rate applied when the business is tax-registered.
     * Make this configurable on Business when rates vary by province.
     */
    private const float TAX_RATE = 0.05;

    public function __construct(
        private readonly FxRateService $fxRateService,
        private readonly InvoiceNumberAllocator $allocator,
    ) {}

    /**
     * Build a draft invoice for the given business/client/period.
     * Idempotent: if an invoice already exists for the (client, period_start) it is returned as-is.
     *
     * @param  string  $period  YYYY-MM
     */
    public function build(Business $business, Client $client, string $period): Invoice
    {
        $periodStart = Carbon::parse($period.'-01');
        $periodEnd = $periodStart->copy()->endOfMonth();

        $existing = Invoice::query()
            ->where('client_id', $client->id)
            ->whereDate('period_start', $periodStart)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $issueCurrency = $client->billing_currency;
        $fxRate = $this->fxRateService->getRate('USD', $issueCurrency, $period);

        $projectIds = $client->projects()->pluck('id');

        $lineItems = CostLineItem::query()
            ->where('period', $period)
            ->whereIn('project_id', $projectIds)
            ->get();

        $invoiceNumber = $this->allocator->nextNumber($business);

        return DB::transaction(function () use (
            $business, $client, $period, $periodStart, $periodEnd,
            $issueCurrency, $fxRate, $projectIds, $lineItems, $invoiceNumber
        ): Invoice {
            $invoice = Invoice::create([
                'business_id' => $business->id,
                'client_id' => $client->id,
                'invoice_number' => $invoiceNumber,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => InvoiceStatus::Draft,
                'issue_currency' => $issueCurrency,
                'subtotal' => 0,
                'total' => 0,
                'fx_rate_snapshot' => $fxRate,
                'fx_rate_source' => $business->fx_source,
                'fx_rate_period' => $period,
                'template_view_snapshot' => $business->invoice_template_view,
                'email_template_view_snapshot' => $business->email_template_view,
                'late_fee_terms_snapshot' => $business->late_fee_terms,
                'hosted_view_token' => bin2hex(random_bytes(32)),
            ]);

            $displayOrder = 0;

            $grouped = $lineItems->groupBy('project_id');
            foreach ($grouped as $projectId => $items) {
                $project = $client->projects()->find($projectId);
                if ($project === null) {
                    continue;
                }

                $baseCostUsd = $items->sum(fn (CostLineItem $i): float => $i->usd_amount);
                $markupType = $project->effectiveMarkupType();
                $markupValue = (float) $project->effectiveMarkupValue();

                $lineAmountUsd = match ($markupType) {
                    MarkupType::Percent => $baseCostUsd * (1 + $markupValue / 100),
                    MarkupType::FixedFee => $baseCostUsd + $markupValue,
                    MarkupType::Hybrid => $baseCostUsd * (1 + $markupValue / 100),
                    MarkupType::Passthrough => $baseCostUsd,
                };

                $lineAmount = round($lineAmountUsd * $fxRate, 2);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'project_id' => $projectId,
                    'label' => $project->name.' — Hosting',
                    'line_type' => InvoiceLineType::Hosting,
                    'amount' => $lineAmount,
                    'source_reference' => 'cost_line_items:project:'.$projectId.':'.$period,
                    'display_order' => $displayOrder++,
                ]);
            }

            $templates = RecurringLineTemplate::query()
                ->where(function ($q) use ($client, $projectIds): void {
                    $q->where('client_id', $client->id)
                        ->orWhereIn('project_id', $projectIds);
                })
                ->where('active_from', '<=', $periodEnd)
                ->where(function ($q) use ($periodStart): void {
                    $q->whereNull('active_to')
                        ->orWhere('active_to', '>=', $periodStart);
                })
                ->get();

            foreach ($templates as $template) {
                if (! $template->cadence->isActiveForPeriod($period)) {
                    continue;
                }

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'project_id' => $template->project_id,
                    'label' => $template->label,
                    'line_type' => InvoiceLineType::Recurring,
                    'amount' => $template->amount,
                    'source_reference' => 'recurring_line_templates:'.$template->id,
                    'display_order' => $displayOrder++,
                ]);
            }

            $subtotal = (float) $invoice->lines()->whereNotIn('line_type', [
                InvoiceLineType::Tax->value,
                InvoiceLineType::Discount->value,
                InvoiceLineType::Credit->value,
            ])->sum('amount');

            $total = $subtotal;

            if ($business->isTaxRegisteredOn($periodStart)) {
                $taxAmount = round($subtotal * self::TAX_RATE, 2);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'label' => 'GST/HST ('.number_format(self::TAX_RATE * 100, 0).'%)',
                    'line_type' => InvoiceLineType::Tax,
                    'amount' => $taxAmount,
                    'display_order' => $displayOrder++,
                ]);

                $total = round($subtotal + $taxAmount, 2);
            }

            $invoice->update(['subtotal' => $subtotal, 'total' => $total]);

            return $invoice->fresh();
        });
    }
}
