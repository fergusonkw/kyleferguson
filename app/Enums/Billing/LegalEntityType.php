<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum LegalEntityType: string
{
    case SoleProprietorship = 'sole_proprietorship';
    case Corporation = 'corporation';
    case Partnership = 'partnership';

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
            self::SoleProprietorship => 'Sole proprietorship',
            self::Corporation => 'Corporation',
            self::Partnership => 'Partnership',
        };
    }
}
