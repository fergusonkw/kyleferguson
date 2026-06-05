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
}
