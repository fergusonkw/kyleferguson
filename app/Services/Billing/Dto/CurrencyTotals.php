<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/**
 * Money totalled per currency, never across them.
 *
 * Invoices are issued in each client's billing currency, so a business with
 * both CAD and USD clients has two receivable figures, not one. Adding them
 * would produce a number that looks authoritative and means nothing, and
 * converting them would make "what am I owed" drift with the exchange rate.
 * So the totals stay separate and the UI shows the business's own currency
 * first, with anything else named alongside it.
 */
final readonly class CurrencyTotals
{
    /**
     * @param  array<string, string>  $amounts  currency code => bcmath amount
     */
    private function __construct(public array $amounts) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<object>  $items
     * @param  callable(object): string  $currency
     * @param  callable(object): string  $amount
     */
    public static function sum(iterable $items, callable $currency, callable $amount): self
    {
        $totals = [];

        foreach ($items as $item) {
            $code = $currency($item);
            $totals[$code] = bcadd($totals[$code] ?? '0.00', $amount($item), 2);
        }

        ksort($totals);

        return new self($totals);
    }

    public static function format(string $amount): string
    {
        return '$'.number_format((float) $amount, 2);
    }

    /**
     * True when there is nothing owed at all — no currencies, or only zeroes.
     */
    public function isEmpty(): bool
    {
        foreach ($this->amounts as $amount) {
            if (bccomp($amount, '0.00', 2) !== 0) {
                return false;
            }
        }

        return true;
    }

    public function get(string $currency): string
    {
        return $this->amounts[$currency] ?? '0.00';
    }

    /**
     * The headline figure: the business's own currency, so the number in the
     * card is stable even when a foreign-currency client comes and goes.
     */
    public function headline(string $preferred): string
    {
        return self::format($this->get($preferred)).' '.$preferred;
    }

    /**
     * Everything other than the headline currency, or null when there is none.
     * Rendered under the headline so a USD balance is never silently dropped.
     */
    public function remainderLabel(string $preferred): ?string
    {
        $others = [];

        foreach ($this->amounts as $currency => $amount) {
            if ($currency !== $preferred && bccomp($amount, '0.00', 2) !== 0) {
                $others[] = self::format($amount).' '.$currency;
            }
        }

        return $others === [] ? null : 'plus '.implode(' and ', $others);
    }

    /**
     * @return list<array{currency: string, amount: string, formatted: string}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->amounts as $currency => $amount) {
            $entries[] = [
                'currency' => $currency,
                'amount' => $amount,
                'formatted' => self::format($amount).' '.$currency,
            ];
        }

        return $entries;
    }
}
