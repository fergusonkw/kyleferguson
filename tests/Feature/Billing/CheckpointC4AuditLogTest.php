<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CheckpointC4AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_creation_writes_audit_log(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.businesses.store'), [
                'name' => 'Acme',
                'contact_email' => 'a@b.c',
                'notification_email' => 'n@b.c',
                'invoice_number_prefix' => 'INV-',
                'default_currency' => 'CAD',
                'supported_currencies' => ['CAD'],
                'fx_source' => 'bank_of_canada',
                'daily_reminder_time' => '08:00',
            ]);

        $log = AuditLog::query()->where('auditable_type', Business::class)->first();
        $this->assertNotNull($log);
        $this->assertSame('created', $log->event);
        $this->assertContains('billing', $log->tags);
        $this->assertContains('business', $log->tags);
    }

    public function test_client_markup_change_is_logged_as_critical(): void
    {
        $client = Client::factory()->withMarkup(\App\Enums\Billing\MarkupType::Percent, 15)->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.clients.update', $client), [
                'name' => $client->name,
                'contact_email' => $client->contact_email,
                'billing_currency' => $client->billing_currency,
                'status' => 'active',
                'default_markup_type' => 'percent',
                'default_markup_value' => 25,
            ])
            ->assertOk();

        $log = AuditLog::query()->where('auditable_type', Client::class)->latest('id')->first();
        $this->assertSame('markup_changed', $log->event);
        $this->assertContains('critical', $log->tags);
        $this->assertContains('markup', $log->tags);
    }

    public function test_client_non_markup_update_is_logged_as_plain_update(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.clients.update', $client), [
                'name' => 'Renamed Co',
                'contact_email' => $client->contact_email,
                'billing_currency' => $client->billing_currency,
                'status' => 'active',
                'default_markup_type' => $client->default_markup_type->value,
                'default_markup_value' => $client->default_markup_value,
            ])
            ->assertOk();

        $log = AuditLog::query()->where('auditable_type', Client::class)->latest('id')->first();
        $this->assertSame('updated', $log->event);
        $this->assertNotContains('critical', $log->tags ?? []);
    }

    public function test_cost_provider_token_rotation_is_logged_as_critical(): void
    {
        Http::fake(['api.digitalocean.com/*' => Http::response(['account' => []], 200)]);

        $provider = CostProvider::factory()->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.cost-providers.update', $provider), [
                'display_name' => $provider->display_name,
                'enabled' => true,
                'token' => str_repeat('z', 64),
            ])
            ->assertOk();

        $log = AuditLog::query()->where('auditable_type', CostProvider::class)->latest('id')->first();
        $this->assertSame('token_rotated', $log->event);
        $this->assertContains('critical', $log->tags);
        $this->assertContains('token', $log->tags);
    }

    public function test_cost_provider_deletion_is_logged_with_token_tag(): void
    {
        $provider = CostProvider::factory()->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.cost-providers.destroy', $provider))
            ->assertOk();

        $log = AuditLog::query()->where('auditable_type', CostProvider::class)->latest('id')->first();
        $this->assertSame('token_deleted', $log->event);
        $this->assertContains('token', $log->tags);
    }

    public function test_project_do_link_change_is_logged_as_critical(): void
    {
        $project = Project::factory()->create();
        $newUuid = '00000000-0000-0000-0000-00000000aaaa';

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.projects.update', $project), [
                'client_id' => $project->client_id,
                'name' => $project->name,
                'status' => 'active',
                'do_project_uuid' => $newUuid,
            ])
            ->assertOk();

        $log = AuditLog::query()->where('auditable_type', Project::class)->latest('id')->first();
        $this->assertSame('do_link_changed', $log->event);
        $this->assertContains('critical', $log->tags);
        $this->assertContains('do-link', $log->tags);
    }
}
