<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a draft invoice for one client and period.
 *
 * Order of operations matters and follows billing-policy.md: costs arrive as a
 * USD basis, convert to the client's billing currency, and only then take the
 * project's markup — so markup is charged on what the work actually cost in
 * the currency the client is billed in.
 *
 * Idempotent on (client, period). Re-running replaces a draft's derived lines
 * while preserving anything the operator added by hand; an invoice that has
 * left draft is never touched.
 */
final class InvoiceBuilder
{
    public function __construct(
        private readonly FxRateService $fxRates,
        private readonly InvoiceNumberAllocator $numbers,
    ) {}

    /**
     * Build (or refresh) the draft for a client and period.
     */
    public function build(Client $client, string $period): Invoice
    {
        BillingPeriod::assertValid($period);

        $existing = Invoice::query()
            ->where('client_id', $client->id)
            ->where('period', $period)
            ->first();

        if ($existing !== null && ! $existing->status->isEditable()) {
            return $existing;
        }

        $business = $client->business;
        $currency = mb_strtoupper($client->billing_currency);
        $periodStart = BillingPeriod::start($period);
        $periodEnd = BillingPeriod::end($period);

        $fxRecord = $this->fxRates->rateRecordFor('USD', $currency, $period);
        $rate = $fxRecord !== null ? (string) $fxRecord->rate : '1';

        return DB::transaction(function () use (
            $client, $business, $period, $periodStart, $periodEnd, $currency, $rate, $fxRecord, $existing
        ): Invoice {
            $invoice = $existing ?? $this->openDraft($client, $business, $period, $periodStart, $periodEnd, $currency);

            $invoice->fill([
                'issue_currency' => $currency,
                'fx_rate_snapshot' => $rate,
                'fx_rate_source' => $fxRecord?->source->value ?? 'internal',
                'fx_rate_period' => $period,
                'template_view_snapshot' => $business->invoice_template_view,
                'email_template_view_snapshot' => $business->email_template_view,
                'late_fee_terms_snapshot' => $business->late_fee_terms,
                'business_snapshot' => $this->snapshotBusiness($business),
                'client_snapshot' => $this->snapshotClient($client),
            ])->save();

            // Derived lines are rebuilt from source each run; manual additions
            // an operator made during review are left alone.
            $invoice->lines()
                ->whereIn('line_type', [InvoiceLineType::Hosting, InvoiceLineType::Recurring, InvoiceLineType::Credit])
                ->delete();

            $order = 0;
            $order = $this->addHostingLines($invoice, $client, $period, $rate, $order);
            $order = $this->addRecurringLines($invoice, $client, $currency, $periodStart, $periodEnd, $order);
            $this->addCarriedCredits($invoice, $client, $period, $order);

            $this->recalculateTotals($invoice);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Recompute subtotal and total from the invoice's lines. Sub-items are
     * excluded — their amounts are already inside their parent.
     */
    public function recalculateTotals(Invoice $invoice): void
    {
        $subtotal = '0.00';

        foreach ($invoice->lines()->where('is_display_only', false)->get() as $line) {
            $subtotal = bcadd($subtotal, $line->amount, 2);
        }

        // No business is GST/HST registered in v1, so tax is zero and the
        // total equals the subtotal. The branch exists for registration day.
        $taxTotal = $invoice->business->isTaxRegisteredOn($invoice->period_end)
            ? $invoice->tax_total
            : '0.00';

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 2),
        ])->save();
    }

    private function openDraft(
        Client $client,
        Business $business,
        string $period,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency,
    ): Invoice {
        return Invoice::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'invoice_number' => $this->numbers->allocate($business),
            'period' => $period,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => InvoiceStatus::Draft,
            'issue_currency' => $currency,
            'template_view_snapshot' => $business->invoice_template_view,
            'email_template_view_snapshot' => $business->email_template_view,
            'hosted_view_token' => Invoice::generateHostedViewToken(),
        ]);
    }

    /**
     * One parent line per project with attributed costs, carrying that
     * project's markup, plus a display-only sub-item per cost category.
     */
    private function addHostingLines(Invoice $invoice, Client $client, string $period, string $rate, int $order): int
    {
        $lines = CostLineItem::query()
            ->forPeriod($period)
            ->whereNotNull('project_id')
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->with('project')
            ->get()
            ->groupBy('project_id');

        foreach ($lines as $projectId => $projectLines) {
            /** @var Project $project */
            $project = $projectLines->first()->project;

            $costUsd = $this->sumCostBasis($projectLines);
            $costInIssueCurrency = $this->convert($costUsd, $rate);
            $charged = $project->applyMarkup($costInIssueCurrency);

            $parent = InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'project_id' => (int) $projectId,
                'label' => 'Hosting — '.$project->name,
                'line_type' => InvoiceLineType::Hosting,
                'amount' => $charged,
                'cost_basis_usd' => $costUsd,
                'source_reference' => "cost_line_items:period={$period};project={$projectId}",
                'is_display_only' => false,
                'display_order' => $order++,
                'metadata' => [
                    'markup_type' => $project->effectiveMarkupType()->value,
                    'markup_percent' => $project->effectiveMarkupValue(),
                    'markup_fee' => $project->effectiveMarkupFee(),
                    'cost_in_issue_currency' => $costInIssueCurrency,
                ],
            ]);

            $order = $this->addCategorySubItems($parent, $projectLines, $rate, $order);
        }

        return $order;
    }

    /**
     * Sub-items break the parent down by category for transparency. They are
     * shown at cost in the issue currency and never summed into the total.
     *
     * @param  Collection<int, CostLineItem>  $projectLines
     */
    private function addCategorySubItems(InvoiceLine $parent, Collection $projectLines, string $rate, int $order): int
    {
        $byCategory = $projectLines->groupBy(fn (CostLineItem $line): string => $line->category->value);

        foreach ($byCategory as $categoryValue => $categoryLines) {
            $category = CostCategory::from((string) $categoryValue);

            InvoiceLine::create([
                'invoice_id' => $parent->invoice_id,
                'parent_id' => $parent->id,
                'project_id' => $parent->project_id,
                'label' => $category->label(),
                'line_type' => InvoiceLineType::Hosting,
                'amount' => $this->convert($this->sumCostBasis($categoryLines), $rate),
                'cost_basis_usd' => $this->sumCostBasis($categoryLines),
                'is_display_only' => true,
                'display_order' => $order++,
            ]);
        }

        return $order;
    }

    private function addRecurringLines(
        Invoice $invoice,
        Client $client,
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $order,
    ): int {
        $templates = RecurringLineTemplate::query()
            ->forClient($client->id)
            ->activeDuring($periodStart, $periodEnd)
            ->orderBy('label')
            ->get();

        foreach ($templates as $template) {
            if (! $template->billsInPeriod($periodStart, $periodEnd)) {
                continue;
            }

            // A template priced in a currency the client is not billed in
            // cannot be silently converted — the operator has to resolve it.
            if (mb_strtoupper($template->currency) !== $currency) {
                throw new RuntimeException(sprintf(
                    'Recurring line "%s" is priced in %s but %s is billed in %s. Fix the template before generating this invoice.',
                    $template->label,
                    $template->currency,
                    $client->name,
                    $currency,
                ));
            }

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'project_id' => $template->project_id,
                'label' => $template->label,
                'line_type' => InvoiceLineType::Recurring,
                'amount' => $template->amount,
                'source_reference' => "recurring_line_templates:{$template->id}",
                'is_display_only' => false,
                'display_order' => $order++,
            ]);
        }

        return $order;
    }

    /**
     * An overpayment on an earlier invoice becomes a credit on the next draft,
     * so the client's money follows them forward rather than needing a refund.
     */
    private function addCarriedCredits(Invoice $invoice, Client $client, string $period, int $order): void
    {
        $overpaid = Invoice::query()
            ->where('client_id', $client->id)
            ->where('period', '<', $period)
            ->where('status', '!=', InvoiceStatus::Void)
            ->get()
            ->filter(fn (Invoice $prior): bool => $prior->isOverpaid());

        foreach ($overpaid as $prior) {
            $alreadyCredited = InvoiceLine::query()
                ->where('line_type', InvoiceLineType::Credit)
                ->where('source_reference', "invoices:{$prior->id}:overpayment")
                ->whereHas('invoice', fn ($q) => $q->where('id', '!=', $invoice->id))
                ->exists();

            if ($alreadyCredited) {
                continue;
            }

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'label' => "Credit — overpayment on {$prior->invoice_number}",
                'line_type' => InvoiceLineType::Credit,
                'amount' => '-'.$prior->overpaymentAmount(),
                'source_reference' => "invoices:{$prior->id}:overpayment",
                'is_display_only' => false,
                'display_order' => $order++,
            ]);
        }
    }

    /**
     * @param  Collection<int, CostLineItem>  $lines
     */
    private function sumCostBasis(Collection $lines): string
    {
        $total = '0.0000';

        foreach ($lines as $line) {
            $total = bcadd($total, $line->usdCostBasis(), 4);
        }

        return $total;
    }

    private function convert(string $usd, string $rate): string
    {
        return number_format((float) bcmul($usd, $rate, 8), 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotBusiness(Business $business): array
    {
        return [
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'address' => $business->address,
            'contact_email' => $business->contact_email,
            'logo_path' => $business->logo_path,
            'brand_primary_color' => $business->brand_primary_color,
            'brand_secondary_color' => $business->brand_secondary_color,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotClient(Client $client): array
    {
        return [
            'name' => $client->name,
            'contact_name' => $client->contact_name,
            'contact_email' => $client->contact_email,
            'billing_address' => $client->billing_address,
            'billing_currency' => $client->billing_currency,
        ];
    }
}
