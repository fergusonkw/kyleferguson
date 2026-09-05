<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CostProviderSlug: string
{
    case DigitalOcean = 'digitalocean';
    case Smtp2go = 'smtp2go';

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
            self::DigitalOcean => 'DigitalOcean',
            self::Smtp2go => 'SMTP2GO',
        };
    }

    /**
     * Account-per-client providers carry a `client_id` and attribute their whole
     * charge to that client. Account-per-business providers attribute per resource.
     */
    public function isAccountPerClient(): bool
    {
        return $this === self::Smtp2go;
    }

    /**
     * Whether the provider independently reports what it billed for a period.
     *
     * Only these providers make a reconciliation cost gap meaningful: where the
     * charge is an operator-entered flat fee (SMTP2GO), the "billed" figure and
     * the ingested figure are the same number, so the gap is zero by
     * construction and is reported as unavailable instead.
     */
    public function reportsAuthoritativeTotal(): bool
    {
        return $this === self::DigitalOcean;
    }
}
