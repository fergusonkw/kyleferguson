<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\ThresholdLevel;
use App\Enums\Billing\ThresholdTest;
use App\Exceptions\Billing\FxRateUnavailableException;
use App\Models\Billing\Business;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\LegalEntity;
use App\Services\Billing\Dto\ThresholdAssessment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tracks a legal entity against the GST/HST small-supplier threshold.
 *
 * A person stops being a small supplier once their taxable supplies — together
 * with those of their associates — pass $30,000 in a single calendar quarter
 * or across four consecutive ones. Every business under the entity counts,
 * because a trade name is not a separate person. See billing-policy.md § Tax.
 *
 * A supply is counted when it is invoiced (`issued_on`) at its CAD value, which
 * is fixed on the invoice at approval so the figure never moves with exchange
 * rates afterwards.
 */
final class SmallSupplierThreshold
{
    public const THRESHOLD_CAD = '30000.00';

    public function __construct(private readonly FxRateService $fxRates) {}

    /**
     * Value an issued invoice in CAD and store it. Returns null, leaving the
     * invoice to be valued later, when its exchange rate cannot be had yet —
     * a missing rate must never stand in the way of approving an invoice.
     */
    public function recordSupplyValue(Invoice $invoice): ?string
    {
        try {
            $value = $this->supplyValueInCad($invoice);
        } catch (FxRateUnavailableException $e) {
            Log::warning('SmallSupplierThreshold: invoice could not be valued in CAD yet', [
                'invoice_id' => $invoice->id,
                'currency' => $invoice->issue_currency,
                'period' => $invoice->period,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $invoice->forceFill(['supply_value_cad' => $value])->save();

        return $value;
    }

    /**
     * Value any counted invoices that are still missing a CAD figure.
     *
     * @return int how many were valued
     */
    public function valueOutstanding(LegalEntity $entity): int
    {
        $valued = 0;

        $this->countedInvoices($this->businessIdsFor($entity))
            ->whereNull('supply_value_cad')
            ->get()
            ->each(function (Invoice $invoice) use (&$valued): void {
                if ($this->recordSupplyValue($invoice) !== null) {
                    $valued++;
                }
            });

        return $valued;
    }

    /**
     * What the supply is worth for the threshold, in CAD.
     *
     * Credit lines are left out: a carried-forward overpayment settles money
     * already received rather than reducing what was supplied. Discounts and
     * adjustments do change the value of the supply, so they stay in.
     *
     * @throws FxRateUnavailableException
     */
    public function supplyValueInCad(Invoice $invoice): string
    {
        $value = $invoice->lines()
            ->where('is_display_only', false)
            ->where('line_type', '!=', InvoiceLineType::Credit->value)
            ->get(['id', 'amount'])
            ->reduce(fn (string $carry, InvoiceLine $line): string => bcadd($carry, $line->amount, 2), '0.00');

        $currency = mb_strtoupper($invoice->issue_currency);

        if ($currency === 'CAD') {
            return $value;
        }

        // The same Bank of Canada monthly average the invoice itself was
        // built on, taken for the period it bills.
        $rate = $this->fxRates->rateFor($currency, 'CAD', $invoice->period);

        return number_format((float) bcmul($value, (string) $rate, 8), 2, '.', '');
    }

    public function assess(LegalEntity $entity, ?CarbonInterface $asOf = null): ThresholdAssessment
    {
        $asOf = CarbonImmutable::parse($asOf ?? now())->startOfDay();
        $group = $entity->thresholdGroup();
        $businesses = Business::query()->whereIn('legal_entity_id', $group->pluck('id'))->orderBy('name')->get();
        $businessIds = $businesses->pluck('id')->all();

        // Five quarters: the four-quarter window ending now, plus the one
        // ending last quarter — a window that tripped just before the quarter
        // rolled over still leaves the entity past the threshold.
        $currentQuarterStart = $asOf->startOfQuarter();
        $quarterStarts = collect(range(4, 0))->map(fn (int $back): CarbonImmutable => $currentQuarterStart->subQuarters($back));

        $invoices = $this->countedInvoices($businessIds)
            ->whereDate('issued_on', '>=', $quarterStarts->first()->toDateString())
            ->whereDate('issued_on', '<=', $currentQuarterStart->endOfQuarter()->toDateString())
            ->get(['id', 'issued_on', 'supply_value_cad']);

        $quarters = $quarterStarts->map(function (CarbonImmutable $start) use ($invoices, $currentQuarterStart): array {
            $end = $start->endOfQuarter();

            return [
                'label' => 'Q'.$start->quarter.' '.$start->year,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'total' => $this->sum($invoices->filter(
                    fn (Invoice $invoice): bool => $invoice->issued_on->betweenIncluded($start, $end),
                )),
                'current' => $start->equalTo($currentQuarterStart),
            ];
        });

        $currentQuarterTotal = $quarters->last()['total'];
        $fourQuarterTotal = $this->sumTotals($quarters->slice(1));
        $previousFourQuarterTotal = $this->sumTotals($quarters->slice(0, 4));

        $exceededBy = match (true) {
            bccomp($currentQuarterTotal, self::THRESHOLD_CAD, 2) === 1 => ThresholdTest::SingleQuarter,
            bccomp($fourQuarterTotal, self::THRESHOLD_CAD, 2) === 1,
            bccomp($previousFourQuarterTotal, self::THRESHOLD_CAD, 2) === 1 => ThresholdTest::FourQuarters,
            default => null,
        };

        return new ThresholdAssessment(
            entity: $entity,
            level: $this->levelFor($entity, $asOf, $exceededBy, $currentQuarterTotal, $fourQuarterTotal),
            threshold: self::THRESHOLD_CAD,
            warningPercent: $entity->threshold_warning_percent,
            quarters: $quarters->slice(1)->values()->all(),
            currentQuarterTotal: $currentQuarterTotal,
            fourQuarterTotal: $fourQuarterTotal,
            previousFourQuarterTotal: $previousFourQuarterTotal,
            exceededBy: $exceededBy,
            uncountedInvoiceCount: $invoices->whereNull('supply_value_cad')->count(),
            entityNames: $group->pluck('name')->values()->all(),
            businessNames: $businesses->pluck('name')->values()->all(),
        );
    }

    private function levelFor(
        LegalEntity $entity,
        CarbonImmutable $asOf,
        ?ThresholdTest $exceededBy,
        string $currentQuarterTotal,
        string $fourQuarterTotal,
    ): ThresholdLevel {
        if ($entity->isTaxRegisteredOn($asOf)) {
            return ThresholdLevel::Registered;
        }

        if ($exceededBy !== null) {
            return ThresholdLevel::Exceeded;
        }

        $warningAt = bcdiv(bcmul(self::THRESHOLD_CAD, (string) $entity->threshold_warning_percent, 2), '100', 2);
        $largest = bccomp($currentQuarterTotal, $fourQuarterTotal, 2) === 1 ? $currentQuarterTotal : $fourQuarterTotal;

        return bccomp($largest, $warningAt, 2) >= 0
            ? ThresholdLevel::Approaching
            : ThresholdLevel::Clear;
    }

    /**
     * Invoices that count as supplies made: issued (approval dates them) and
     * not voided. A voided invoice was never a supply.
     *
     * @param  list<int>  $businessIds
     * @return Builder<Invoice>
     */
    private function countedInvoices(array $businessIds): Builder
    {
        return Invoice::query()
            ->whereIn('business_id', $businessIds)
            ->whereNotNull('issued_on')
            ->whereIn('status', [
                InvoiceStatus::Approved,
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
            ]);
    }

    /**
     * @return list<int>
     */
    private function businessIdsFor(LegalEntity $entity): array
    {
        return Business::query()
            ->whereIn('legal_entity_id', $entity->thresholdGroup()->pluck('id'))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function sum(Collection $invoices): string
    {
        return $invoices->reduce(
            fn (string $carry, Invoice $invoice): string => bcadd($carry, (string) ($invoice->supply_value_cad ?? '0'), 2),
            '0.00',
        );
    }

    /**
     * @param  Collection<int, array{label: string, start: string, end: string, total: string, current: bool}>  $quarters
     */
    private function sumTotals(Collection $quarters): string
    {
        return $quarters->reduce(fn (string $carry, array $quarter): string => bcadd($carry, $quarter['total'], 2), '0.00');
    }
}
