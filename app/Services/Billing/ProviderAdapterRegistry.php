<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Exceptions\Billing\UnsupportedProviderCapability;
use App\Services\Billing\Contracts\BillingSyncAdapter;
use App\Services\Billing\Contracts\CredentialValidator;
use App\Services\Billing\Contracts\ResourceSyncAdapter;
use App\Services\Billing\DigitalOcean\ProjectSync;
use App\Services\Billing\Smtp2go\BillingSync as Smtp2goBillingSync;
use Illuminate\Contracts\Container\Container;

/**
 * Maps a cost provider to the adapters implementing its capabilities. A
 * provider implements only what it actually supports: DigitalOcean has a
 * resource inventory but its billing ingestion is parked; SMTP2GO has no
 * resource inventory but does ingest a billing figure.
 */
final class ProviderAdapterRegistry
{
    /**
     * Slug → capability → adapter class.
     *
     * @var array<string, array{resource_sync?: class-string<ResourceSyncAdapter>, billing_sync?: class-string<BillingSyncAdapter>, credential_validator?: class-string<CredentialValidator>}>
     */
    private const ADAPTERS = [
        CostProviderSlug::DigitalOcean->value => [
            'resource_sync' => ProjectSync::class,
            'credential_validator' => ProjectSync::class,
        ],
        CostProviderSlug::Smtp2go->value => [
            'billing_sync' => Smtp2goBillingSync::class,
            'credential_validator' => Smtp2goBillingSync::class,
        ],
    ];

    public function __construct(private readonly Container $container) {}

    public function supportsResourceSync(CostProviderSlug $slug): bool
    {
        return isset(self::ADAPTERS[$slug->value]['resource_sync']);
    }

    public function supportsBillingSync(CostProviderSlug $slug): bool
    {
        return isset(self::ADAPTERS[$slug->value]['billing_sync']);
    }

    public function supportsCredentialValidation(CostProviderSlug $slug): bool
    {
        return isset(self::ADAPTERS[$slug->value]['credential_validator']);
    }

    public function resourceSyncFor(CostProviderSlug $slug): ResourceSyncAdapter
    {
        /** @var ResourceSyncAdapter */
        return $this->resolve($slug, 'resource_sync', 'resource sync');
    }

    public function billingSyncFor(CostProviderSlug $slug): BillingSyncAdapter
    {
        /** @var BillingSyncAdapter */
        return $this->resolve($slug, 'billing_sync', 'billing sync');
    }

    public function credentialValidatorFor(CostProviderSlug $slug): CredentialValidator
    {
        /** @var CredentialValidator */
        return $this->resolve($slug, 'credential_validator', 'credential validation');
    }

    /**
     * Slugs that can have their billing ingested — drives the scheduler and the
     * "sync billing" affordance in the admin UI.
     *
     * @return list<CostProviderSlug>
     */
    public function billingSyncSlugs(): array
    {
        return array_values(array_filter(
            CostProviderSlug::cases(),
            fn (CostProviderSlug $slug): bool => $this->supportsBillingSync($slug),
        ));
    }

    private function resolve(CostProviderSlug $slug, string $capability, string $label): object
    {
        $class = self::ADAPTERS[$slug->value][$capability] ?? null;

        if ($class === null) {
            throw UnsupportedProviderCapability::for($slug, $label);
        }

        return $this->container->make($class);
    }
}
