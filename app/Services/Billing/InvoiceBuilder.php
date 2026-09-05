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
        private readonly InvoiceSnapshotter $snapshots,
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
            ]);

            // A draft is still a working document, so rebuilding re-takes the
            // business and client details rather than keeping the first copy.
            $this->snapshots->capture($invoice);
            $invoice->save();

            // Derived lines are rebuilt from source each run; manual additions
            // an operator made during review are left alone.
            $invoice->lines()
                ->whereIn('line_type', [InvoiceLineType::Hosting, InvoiceLineType::Recurring, InvoiceLineType::Credit])
                ->delete();

            $order = 0;
            $order = $this->addHostingLines($invoice, $client, $period, $rate, $order);
            $order = $this->addRecurringLines($invoice, $client, $currency, $period, $periodStart, $periodEnd, $order);
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
            ->with(['project', 'costProvider'])
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
                'label' => $this->serviceLabelFor($projectLines).' — '.$project->name,
                'description' => $this->serviceDescriptionFor($projectLines),
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
                    'markup_summary' => $project->effectiveMarkupType()->describe(
                        $project->effectiveMarkupValue(),
                        $project->effectiveMarkupFee(),
                    ),
                ],
            ]);

            $order = $this->addCategorySubItems($parent, $projectLines, $charged, $order);
        }

        return $order;
    }

    /**
     * Name the line after the services in it, not after "hosting".
     *
     * All of a project's provider costs stay on one line because markup is
     * charged once per project — splitting per provider would apply a flat fee
     * once each. So the label names every service contributing to it.
     *
     * @param  Collection<int, CostLineItem>  $projectLines
     */
    private function serviceLabelFor(Collection $projectLines): string
    {
        $labels = $projectLines
            ->map(fn (CostLineItem $line): string => $line->costProvider->invoiceLabel())
            ->unique()
            ->sort()
            ->values();

        return $labels->count() <= 2
            ? $labels->implode(' & ')
            : 'Services';
    }

    /**
     * A single provider's own description carries through to the line. With
     * several, the sub-items already name each service, so an aggregate
     * description would only repeat them.
     *
     * @param  Collection<int, CostLineItem>  $projectLines
     */
    private function serviceDescriptionFor(Collection $projectLines): ?string
    {
        $providers = $projectLines->map(fn (CostLineItem $line) => $line->costProvider)->unique('id');

        return $providers->count() === 1
            ? ($providers->first()->invoice_description ?: null)
            : null;
    }

    /**
     * Sub-items break the parent down by category for transparency. They are
     * shown at cost in the issue currency and never summed into the total.
     *
     * @param  Collection<int, CostLineItem>  $projectLines
     */
    private function addCategorySubItems(
        InvoiceLine $parent,
        Collection $projectLines,
        string $charged,
        int $order,
    ): int {
        // Grouped by provider as well as category: "Compute" alone is ambiguous
        // once a project draws on more than one service, and a client reading
        // the breakdown needs to know which is which.
        $multipleProviders = $projectLines->pluck('cost_provider_id')->unique()->count() > 1;

        $groups = $projectLines->groupBy(
            fn (CostLineItem $line): string => $line->cost_provider_id.'|'.$line->category->value,
        );

        // A single sub-item breaks nothing down — it just restates the parent's
        // figure underneath itself, which reads like a duplicate charge.
        if ($groups->count() < 2) {
            return $order;
        }

        // Sub-items show each component's share of what is being charged, not
        // its raw cost. Showing cost beside a marked-up parent would both fail
        // to add up and let the client read the margin by subtraction.
        $totalCostUsd = $this->sumCostBasis($projectLines);
        $shares = $this->distribute($groups, $totalCostUsd, $charged);
        $index = 0;

        foreach ($groups as $groupLines) {
            /** @var CostLineItem $first */
            $first = $groupLines->first();
            $category = CostCategory::from($first->category->value);

            $label = $multipleProviders
                ? $first->costProvider->invoiceLabel().' · '.$category->label()
                : $category->label();

            InvoiceLine::create([
                'invoice_id' => $parent->invoice_id,
                'parent_id' => $parent->id,
                'project_id' => $parent->project_id,
                'label' => $label,
                'line_type' => InvoiceLineType::Hosting,
                'amount' => $shares[$index++],
                'cost_basis_usd' => $this->sumCostBasis($groupLines),
                'is_display_only' => true,
                'display_order' => $order++,
            ]);
        }

        return $order;
    }

    /**
     * Split the charged amount across groups in proportion to their cost.
     *
     * Rounding remainders go to the largest group, so the sub-items always sum
     * to the parent exactly — a breakdown that does not add up is worse than
     * no breakdown.
     *
     * @param  Collection<string, Collection<int, CostLineItem>>  $groups
     * @return list<string>
     */
    private function distribute(Collection $groups, string $totalCostUsd, string $charged): array
    {
        $count = $groups->count();

        if ($count === 0) {
            return [];
        }

        if (bccomp($totalCostUsd, '0.0000', 4) !== 1) {
            // No cost to weight by: split evenly and let the remainder land on
            // the first group.
            $even = bcdiv($charged, (string) $count, 2);
            $shares = array_fill(0, $count, $even);
            $shares[0] = bcadd($shares[0], bcsub($charged, bcmul($even, (string) $count, 2), 2), 2);

            return $shares;
        }

        $shares = [];
        $largestIndex = 0;
        $largestCost = '0.0000';
        $index = 0;

        foreach ($groups as $groupLines) {
            $groupCost = $this->sumCostBasis($groupLines);
            $shares[] = number_format(
                (float) bcdiv(bcmul($charged, $groupCost, 8), $totalCostUsd, 8),
                2,
                '.',
                '',
            );

            if (bccomp($groupCost, $largestCost, 4) === 1) {
                $largestCost = $groupCost;
                $largestIndex = $index;
            }

            $index++;
        }

        $assigned = array_reduce($shares, fn (string $carry, string $s): string => bcadd($carry, $s, 2), '0.00');
        $shares[$largestIndex] = bcadd($shares[$largestIndex], bcsub($charged, $assigned, 2), 2);

        return $shares;
    }

    private function addRecurringLines(
        Invoice $invoice,
        Client $client,
        string $currency,
        string $period,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $order,
    ): int {
        $templates = RecurringLineTemplate::query()
            ->forClient($client->id)
            ->activeDuring($periodStart, $periodEnd)
            ->with('project')
            ->orderBy('label')
            ->get();

        foreach ($templates as $template) {
            if (! $template->billsInPeriod($periodStart, $periodEnd)) {
                continue;
            }

            // A standing charge attached to a project stops when the project
            // does. The template's own window governs client-wide charges;
            // nothing but this stops a terminated project billing forever.
            if ($template->project?->hadTerminatedBefore($periodStart) === true) {
                continue;
            }

            // A recurring item can be priced in the currency it is actually
            // bought in — a domain renewal billed in USD to a CAD client. It
            // converts at the period's rate, the same as a manual line, and
            // keeps what was charged so the invoice can show its working.
            $templateCurrency = mb_strtoupper($template->currency);
            $converted = $templateCurrency !== $currency;
            $rate = $this->fxRates->rateFor($templateCurrency, $currency, $period);
            $amount = number_format((float) $template->amount * $rate, 2, '.', '');

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'project_id' => $template->project_id,
                'label' => $template->label,
                'line_type' => InvoiceLineType::Recurring,
                'amount' => $amount,
                'source_amount' => $converted ? $template->amount : null,
                'source_currency' => $converted ? $templateCurrency : null,
                'fx_rate_applied' => $converted ? $rate : null,
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
}
