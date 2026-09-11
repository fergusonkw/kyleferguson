<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * SMTP2GO serves its API from regional endpoints. An account only answers on
 * the region it was provisioned in, so this is part of provider config.
 */
enum Smtp2goRegion: string
{
    case Global = 'global';
    case Us = 'us';
    case Eu = 'eu';
    case Au = 'au';

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
            self::Global => 'Global (default)',
            self::Us => 'United States',
            self::Eu => 'Europe',
            self::Au => 'Australia',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Global => 'https://api.smtp2go.com/v3',
            self::Us => 'https://us-api.smtp2go.com/v3',
            self::Eu => 'https://eu-api.smtp2go.com/v3',
            self::Au => 'https://au-api.smtp2go.com/v3',
        };
    }
}
