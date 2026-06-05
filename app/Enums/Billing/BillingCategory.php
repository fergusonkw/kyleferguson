<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum BillingCategory: string
{
    case Droplet = 'droplet';
    case Database = 'database';
    case Space = 'space';
    case Kubernetes = 'kubernetes';
    case Volume = 'volume';
    case LoadBalancer = 'load_balancer';
    case Bandwidth = 'bandwidth';
    case Tax = 'tax';
    case Overhead = 'overhead';
    case Other = 'other';

    public static function fromDoProduct(string $product): self
    {
        return match (strtolower(trim($product))) {
            'droplets' => self::Droplet,
            'databases' => self::Database,
            'spaces' => self::Space,
            'kubernetes' => self::Kubernetes,
            'volumes' => self::Volume,
            'load balancers' => self::LoadBalancer,
            'bandwidth' => self::Bandwidth,
            'tax' => self::Tax,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Droplet => 'Droplets',
            self::Database => 'Databases',
            self::Space => 'Spaces',
            self::Kubernetes => 'Kubernetes',
            self::Volume => 'Volumes',
            self::LoadBalancer => 'Load Balancers',
            self::Bandwidth => 'Bandwidth',
            self::Tax => 'Tax',
            self::Overhead => 'Overhead',
            self::Other => 'Other',
        };
    }
}
