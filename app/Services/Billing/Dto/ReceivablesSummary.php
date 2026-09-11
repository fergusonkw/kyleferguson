<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/**
 * What a business is owed, and what is standing between an invoice and being
 * paid, at a moment in time.
 *
 * The three states are deliberately distinct because they fail differently: an
 * approved invoice nobody sent is a mistake only the operator can see, an
 * overdue invoice needs chasing, and a draft is simply unfinished work.
 */
final readonly class ReceivablesSummary
{
    public function __construct(
        public string $currency,
        public CurrencyTotals $outstanding,
        public CurrencyTotals $overdue,
        public CurrencyTotals $awaitingSend,
        public CurrencyTotals $collectedRecently,
        public int $outstandingCount = 0,
        public int $overdueCount = 0,
        public int $awaitingSendCount = 0,
        public int $draftCount = 0,
        public int $collectedDays = 30,

        /**
         * Days past due of the oldest unpaid invoice. Null when nothing is
         * overdue — the age of the worst case says more than an average.
         */
        public ?int $oldestOverdueDays = null,

        /**
         * Issued invoices carrying no due date, so nothing can tell whether
         * they are late. Approval sets one, so this counts the ones that
         * predate that or had it cleared.
         */
        public int $missingDueDateCount = 0,
    ) {}

    public function hasOverdue(): bool
    {
        return $this->overdueCount > 0;
    }

    /**
     * Whether anything here wants the operator's attention rather than just
     * reporting a number.
     */
    public function needsAttention(): bool
    {
        return $this->overdueCount > 0
            || $this->awaitingSendCount > 0
            || $this->missingDueDateCount > 0;
    }
}
