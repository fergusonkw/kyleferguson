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
