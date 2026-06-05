<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\Cadence;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\Gateways\InteracGateway;
use App\Services\Billing\Gateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CheckpointLRecurringTemplateAndGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->create(['business_id' => $this->business->id]);
    }

    // --- Recurring line template CRUD ---

    public function test_admin_can_list_recurring_templates(): void
    {
        RecurringLineTemplate::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->createAdmin())
            ->withSession(['billing_current_business_id' => $this->business->id])
            ->getJson(route('admin.billing.recurring-line-templates.data', ['draw' => 1, 'length' => 25]))
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_admin_can_create_recurring_template(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-line-templates.store'), [
                'client_id' => $this->client->id,
                'label' => 'Monthly Retainer',
                'amount' => 500.00,
                'currency' => 'CAD',
                'cadence' => Cadence::Monthly->value,
                'active_from' => '2026-01-01',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('recurring_line_templates', [
            'client_id' => $this->client->id,
            'label' => 'Monthly Retainer',
        ]);
    }

    public function test_admin_can_update_recurring_template(): void
    {
        $template = RecurringLineTemplate::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.recurring-line-templates.update', $template), [
                'client_id' => $this->client->id,
                'label' => 'Updated Retainer',
                'amount' => 750.00,
                'currency' => 'CAD',
                'cadence' => Cadence::Monthly->value,
                'active_from' => '2026-01-01',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('recurring_line_templates', [
            'id' => $template->id,
            'label' => 'Updated Retainer',
        ]);
    }

    public function test_admin_can_delete_recurring_template(): void
    {
        $template = RecurringLineTemplate::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.recurring-line-templates.destroy', $template))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('recurring_line_templates', ['id' => $template->id]);
    }

    public function test_store_requires_client_or_project(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-line-templates.store'), [
                'label' => 'Orphan Template',
                'amount' => 100.00,
                'currency' => 'CAD',
                'cadence' => Cadence::Monthly->value,
                'active_from' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    // --- Payment gateway placeholders ---

    public function test_stripe_gateway_is_not_available(): void
    {
        $gateway = new StripeGateway();

        $this->assertFalse($gateway->isAvailable());
    }

    public function test_stripe_gateway_charge_throws_not_implemented(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stripe gateway is not yet implemented.');

        (new StripeGateway())->charge(100.00, 'CAD', []);
    }

    public function test_interac_gateway_is_not_available(): void
    {
        $gateway = new InteracGateway();

        $this->assertFalse($gateway->isAvailable());
    }

    public function test_interac_gateway_charge_throws_not_implemented(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Interac gateway is not yet implemented.');

        (new InteracGateway())->charge(100.00, 'CAD', []);
    }
}
