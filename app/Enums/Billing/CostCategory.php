<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CostCategory: string
{
    case Compute = 'compute';
    case Database = 'database';
    case Storage = 'storage';
    case Bandwidth = 'bandwidth';
    case Backup = 'backup';
    case Snapshot = 'snapshot';
    case LoadBalancer = 'load_balancer';
    case Email = 'email';
    case Support = 'support';
    case Credit = 'credit';
    case Tax = 'tax';
    case Overhead = 'overhead';
    case Other = 'other';

    /**
     * Category values that are never passed through to a client, for use in
     * queries that cannot call {@see self::isAttributable()} per row.
     *
     * @return list<string>
     */
    public static function nonAttributableValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => ! $case->isAttributable()),
        ));
    }

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
            self::Compute => 'Compute',
            self::Database => 'Database',
            self::Storage => 'Storage',
            self::Bandwidth => 'Bandwidth',
            self::Backup => 'Backup',
            self::Snapshot => 'Snapshot',
            self::LoadBalancer => 'Load balancer',
            self::Email => 'Email',
            self::Support => 'Support',
            self::Credit => 'Credit',
            self::Tax => 'Tax',
            self::Overhead => 'Overhead',
            self::Other => 'Other',
        };
    }

    /**
     * Whether a cost in this category may ever be attributed to a client project.
     * Account-level charges are absorbed as overhead and never passed through.
     */
    public function isAttributable(): bool
    {
        return match ($this) {
            self::Support, self::Credit, self::Overhead => false,
            default => true,
        };
    }
}
