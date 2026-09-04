<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Exceptions\Billing\UnsupportedProviderCapability;
use App\Services\Billing\Contracts\CredentialValidator;
use App\Services\Billing\Contracts\ResourceSyncAdapter;
use App\Services\Billing\DigitalOcean\ProjectSync;
use App\Services\Billing\ProviderAdapterRegistry;
use Tests\TestCase;

/**
 * The registry is what keeps provider-specific branching out of controllers,
 * jobs, and the scheduler. A provider exposes only the capabilities it has.
 */
final class CheckpointD5RegistryTest extends TestCase
{
    public function test_digitalocean_resolves_its_resource_sync_adapter(): void
    {
        $adapter = $this->registry()->resourceSyncFor(CostProviderSlug::DigitalOcean);

        $this->assertInstanceOf(ProjectSync::class, $adapter);
        $this->assertInstanceOf(ResourceSyncAdapter::class, $adapter);
    }

    public function test_digitalocean_resolves_its_credential_validator(): void
    {
        $adapter = $this->registry()->credentialValidatorFor(CostProviderSlug::DigitalOcean);

        $this->assertInstanceOf(CredentialValidator::class, $adapter);
    }

    public function test_digitalocean_billing_sync_is_parked_and_reports_unsupported(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->supportsBillingSync(CostProviderSlug::DigitalOcean));

        $this->expectException(UnsupportedProviderCapability::class);
        $this->expectExceptionMessage('does not support billing sync');

        $registry->billingSyncFor(CostProviderSlug::DigitalOcean);
    }

    public function test_smtp2go_has_no_resource_inventory(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->supportsResourceSync(CostProviderSlug::Smtp2go));

        $this->expectException(UnsupportedProviderCapability::class);
        $this->expectExceptionMessage('does not support resource sync');

        $registry->resourceSyncFor(CostProviderSlug::Smtp2go);
    }

    public function test_capability_probes_agree_with_resolution(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->supportsResourceSync(CostProviderSlug::DigitalOcean));
        $this->assertTrue($registry->supportsCredentialValidation(CostProviderSlug::DigitalOcean));
    }

    public function test_billing_sync_slugs_lists_only_ingestible_providers(): void
    {
        $slugs = $this->registry()->billingSyncSlugs();

        $this->assertNotContains(CostProviderSlug::DigitalOcean, $slugs);
    }

    public function test_adapters_are_resolved_through_the_container(): void
    {
        $registry = $this->registry();

        $first = $registry->resourceSyncFor(CostProviderSlug::DigitalOcean);
        $second = $registry->resourceSyncFor(CostProviderSlug::DigitalOcean);

        $this->assertInstanceOf(ProjectSync::class, $first);
        $this->assertInstanceOf(ProjectSync::class, $second);
    }

    private function registry(): ProviderAdapterRegistry
    {
        return app(ProviderAdapterRegistry::class);
    }
}
