<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Services\Billing\Dto\CurrencyTotals;
use App\Services\Billing\Dto\ReceivablesSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Answers "who owes me money, and who is late?"
 *
 * The invoicing engine has always known this — every figure here is derived
 * from invoice totals and payment records that already existed. What was
 * missing was anywhere to read it: a payment could only be seen by opening the
 * one invoice it belonged to, and `due_on` was written at approval and then
 * never looked at again by anything.
 *
 * "Owed" means issued and unpaid. An approved invoice that was never sent is
 * not owed — the client has not been asked — but it is reported separately,
 * because an invoice approved and then forgotten is money lost silently.
 */
final class ReceivablesReporter
{
    /**
     * Statuses that mean the client has the invoice and has not settled it.
     *
     * @var list<InvoiceStatus>
     */
    private const OWED = [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid];

    /**
     * The receivables page asks for the outstanding list three times over — for
     * the cards, the ageing buckets and the table. They must agree, and one
     * pass is enough to answer all three.
     *
     * @var array<int, Collection<int, Invoice>>
     */
    private array $outstandingCache = [];

    public function summarize(Business $business, ?Carbon $asOf = null): ReceivablesSummary
    {
        $asOf ??= now();
        $businessId = $business->id;

        $owed = $this->outstandingInvoices($businessId);
        $overdue = $owed->filter(fn (Invoice $i): bool => $this->isOverdue($i, $asOf));

        $awaitingSend = $this->withBalances(
            $this->baseQuery($businessId)->where('status', InvoiceStatus::Approved),
        );

        return new ReceivablesSummary(
            currency: $business->default_currency,
            outstanding: $this->totalBalances($owed),
            overdue: $this->totalBalances($overdue),
            awaitingSend: $this->totalBalances($awaitingSend),
            collectedRecently: $this->collectedSince($businessId, $asOf->copy()->subDays(30)),
            outstandingCount: $owed->count(),
            overdueCount: $overdue->count(),
            awaitingSendCount: $awaitingSend->count(),
            draftCount: $this->baseQuery($businessId)->where('status', InvoiceStatus::Draft)->count(),
            oldestOverdueDays: $this->oldestOverdueDays($overdue, $asOf),
            missingDueDateCount: $owed->whereNull('due_on')->count(),
        );
    }

    /**
     * Issued invoices with money still on them, oldest due date first — the
     * order they should be chased in.
     *
     * @return Collection<int, Invoice>
     */
    public function outstandingInvoices(int $businessId): Collection
    {
        return $this->outstandingCache[$businessId] ??= $this->withBalances(
            $this->baseQuery($businessId)
                ->whereIn('status', self::OWED)
                ->orderByRaw('due_on is null, due_on asc'),
        );
    }

    /**
     * Outstanding balances bucketed by how far past due they are.
     *
     * Invoices with no due date sit in their own bucket rather than being
     * assumed current: not knowing whether something is late is a different
     * situation from knowing it is not.
     *
     * @return list<array{key: string, label: string, count: int, totals: CurrencyTotals}>
     */
    public function aging(int $businessId, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $invoices = $this->outstandingInvoices($businessId);

        $buckets = [
            'not_due' => 'Not yet due',
            '1_30' => '1–30 days',
            '31_60' => '31–60 days',
            '61_90' => '61–90 days',
            '90_plus' => 'Over 90 days',
            'no_due_date' => 'No due date',
        ];

        $grouped = $invoices->groupBy(fn (Invoice $i): string => $this->bucketFor($i, $asOf));

        $rows = [];

        foreach ($buckets as $key => $label) {
            /** @var Collection<int, Invoice> $inBucket */
            $inBucket = $grouped->get($key) ?? new Collection;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'count' => $inBucket->count(),
                'totals' => $this->totalBalances($inBucket),
            ];
        }

        return $rows;
    }

    /**
     * Payments received across every invoice, newest first. The ledger the
     * per-invoice view could never show.
     *
     * @return Collection<int, Payment>
     */
    public function recentPayments(int $businessId, int $limit = 25): Collection
    {
        return Payment::query()
            ->whereHas('invoice', fn (Builder $q): Builder => $q->where('business_id', $businessId))
            ->with(['invoice.client', 'recordedBy'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function daysOverdue(Invoice $invoice, ?Carbon $asOf = null): ?int
    {
        if ($invoice->due_on === null) {
            return null;
        }

        $asOf ??= now();
        $days = $invoice->due_on->diffInDays($asOf->copy()->startOfDay(), false);

        return $days > 0 ? (int) $days : null;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function totalBalances(Collection $invoices): CurrencyTotals
    {
        return CurrencyTotals::sum(
            $invoices,
            fn (Invoice $i): string => $i->issue_currency,
            fn (Invoice $i): string => $i->balanceDue(),
        );
    }

    /**
     * Payments are recorded in the currency of the invoice they settle.
     */
    private function collectedSince(int $businessId, Carbon $since): CurrencyTotals
    {
        $payments = Payment::query()
            ->whereHas('invoice', fn (Builder $q): Builder => $q->where('business_id', $businessId))
            ->where('received_at', '>=', $since)
            ->with('invoice')
            ->get();

        return CurrencyTotals::sum(
            $payments,
            fn (Payment $p): string => $p->invoice->issue_currency,
            fn (Payment $p): string => (string) $p->amount,
        );
    }

    /**
     * @param  Collection<int, Invoice>  $overdue
     */
    private function oldestOverdueDays(Collection $overdue, Carbon $asOf): ?int
    {
        return $overdue
            ->map(fn (Invoice $i): ?int => $this->daysOverdue($i, $asOf))
            ->filter()
            ->max();
    }

    private function isOverdue(Invoice $invoice, Carbon $asOf): bool
    {
        return $this->daysOverdue($invoice, $asOf) !== null;
    }

    private function bucketFor(Invoice $invoice, Carbon $asOf): string
    {
        if ($invoice->due_on === null) {
            return 'no_due_date';
        }

        $days = $this->daysOverdue($invoice, $asOf);

        return match (true) {
            $days === null => 'not_due',
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => '90_plus',
        };
    }

    /**
     * A zero balance means paid in full even if the status has not caught up,
     * so the balance decides what is owed rather than the status alone.
     *
     * @param  Builder<Invoice>  $query
     * @return Collection<int, Invoice>
     */
    private function withBalances(Builder $query): Collection
    {
        return $query->get()->filter(
            fn (Invoice $i): bool => bccomp($i->balanceDue(), '0.00', 2) === 1,
        )->values();
    }

    /**
     * @return Builder<Invoice>
     */
    private function baseQuery(int $businessId): Builder
    {
        return Invoice::query()
            ->where('business_id', $businessId)
            ->where('status', '!=', InvoiceStatus::Void)
            ->with('client')
            // One aggregate for the whole list rather than a sum per invoice;
            // {@see Invoice::amountPaid()} picks it up.
            ->withSum('payments', 'amount');
    }
}
