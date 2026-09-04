<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum MarkupType: string
{
    case Percent = 'percent';
    case FixedFee = 'fixed_fee';
    case Hybrid = 'hybrid';
    case Passthrough = 'passthrough';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percent markup',
            self::FixedFee => 'Fixed fee',
            self::Hybrid => 'Fee + percent',
            self::Passthrough => 'Pass-through (no markup)',
        };
    }

    /**
     * Apply this markup to a cost, in the invoice's issue currency.
     *
     * `$percent` and `$fee` are the two components every markup is expressed
     * in; a type simply ignores the one it does not use. Arithmetic is bcmath
     * at 4dp and rounded to 2 at the end, so a long chain of project lines
     * cannot drift the invoice total by a cent.
     */
    public function apply(string $cost, string $percent, string $fee): string
    {
        $total = match ($this) {
            self::Passthrough => $cost,
            self::Percent => bcadd($cost, $this->percentOf($cost, $percent), 4),
            self::FixedFee => bcadd($cost, $fee, 4),
            self::Hybrid => bcadd(bcadd($cost, $this->percentOf($cost, $percent), 4), $fee, 4),
        };

        return $this->round2($total);
    }

    /**
     * Whether the type charges a percentage of cost — drives which fields the
     * markup form shows.
     */
    public function usesPercent(): bool
    {
        return match ($this) {
            self::Percent, self::Hybrid => true,
            default => false,
        };
    }

    public function usesFee(): bool
    {
        return match ($this) {
            self::FixedFee, self::Hybrid => true,
            default => false,
        };
    }

    private function percentOf(string $cost, string $percent): string
    {
        return bcdiv(bcmul($cost, $percent, 8), '100', 4);
    }

    private function round2(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
