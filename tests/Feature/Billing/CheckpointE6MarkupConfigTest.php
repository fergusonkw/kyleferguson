<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Markup configuration through the admin.
 *
 * Splitting markup into a percent and a fee left `fixed_fee` and `hybrid`
 * selectable but unusable — there was no field for the fee. These cover the
 * whole configuration path so that cannot recur.
 */
final class CheckpointE6MarkupConfigTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['supported_currencies' => ['CAD']]);
    }

    public function test_markup_is_described_consistently_for_each_type(): void
    {
        $this->assertSame('pass-through', MarkupType::Passthrough->describe('0', '0'));
        $this->assertSame('15%', MarkupType::Percent->describe('15', '0'));
        $this->assertSame('$40.00', MarkupType::FixedFee->describe('0', '40'));
        $this->assertSame('$40.00 + 15%', MarkupType::Hybrid->describe('15', '40'));
    }

    public function test_a_type_declares_which_components_it_uses(): void
    {
        $this->assertTrue(MarkupType::Percent->usesPercent());
        $this->assertFalse(MarkupType::Percent->usesFee());

        $this->assertFalse(MarkupType::FixedFee->usesPercent());
        $this->assertTrue(MarkupType::FixedFee->usesFee());

        $this->assertTrue(MarkupType::Hybrid->usesPercent());
        $this->assertTrue(MarkupType::Hybrid->usesFee());

        $this->assertFalse(MarkupType::Passthrough->usesPercent());
        $this->assertFalse(MarkupType::Passthrough->usesFee());
    }

    public function test_a_client_can_be_given_a_fixed_fee_default(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::FixedFee->value,
                'default_markup_fee' => '40.00',
            ]))
            ->assertOk();

        $client = Client::query()->firstOrFail();
        $this->assertSame(MarkupType::FixedFee, $client->default_markup_type);
        $this->assertSame('40.0000', $client->default_markup_fee);
    }

    public function test_a_client_can_be_given_a_hybrid_default(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::Hybrid->value,
                'default_markup_value' => '15',
                'default_markup_fee' => '40.00',
            ]))
            ->assertOk();

        $client = Client::query()->firstOrFail();
        $this->assertSame('15.0000', $client->default_markup_value);
        $this->assertSame('40.0000', $client->default_markup_fee);
    }

    public function test_a_fixed_fee_default_without_a_fee_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::FixedFee->value,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_markup_fee']);
    }

    public function test_a_percent_default_without_a_percent_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::Percent->value,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_markup_value']);
    }

    public function test_a_hybrid_default_needs_both_components(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::Hybrid->value,
                'default_markup_value' => '15',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_markup_fee']);
    }

    public function test_a_passthrough_default_needs_neither(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.clients.store'), $this->clientPayload([
                'default_markup_type' => MarkupType::Passthrough->value,
            ]))
            ->assertOk();
    }

    public function test_a_project_can_override_with_a_hybrid_markup(): void
    {
        $client = Client::factory()->for($this->business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Acme Production',
                'status' => 'active',
                'markup_type' => MarkupType::Hybrid->value,
                'markup_value' => '10',
                'markup_fee' => '25.00',
            ])
            ->assertOk();

        $project = Project::query()->firstOrFail();
        $this->assertSame('10.0000', $project->markup_value);
        $this->assertSame('25.0000', $project->markup_fee);
        $this->assertSame('$25.00 + 10%', $project->effectiveMarkupType()->describe(
            $project->effectiveMarkupValue(), $project->effectiveMarkupFee(),
        ));
    }

    public function test_a_project_override_missing_its_fee_is_rejected(): void
    {
        $client = Client::factory()->for($this->business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Acme Production',
                'status' => 'active',
                'markup_type' => MarkupType::FixedFee->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['markup_fee']);
    }

    public function test_a_project_with_no_override_inherits_and_needs_no_components(): void
    {
        $client = Client::factory()->for($this->business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Acme Production',
                'status' => 'active',
                'markup_type' => null,
            ])
            ->assertOk();

        $this->assertNull(Project::query()->firstOrFail()->markup_type);
    }

    public function test_the_project_list_names_the_inherited_markup(): void
    {
        $client = Client::factory()->for($this->business)->create([
            'default_markup_type' => MarkupType::Percent,
            'default_markup_value' => 15,
        ]);
        Project::factory()->for($client)->create(['markup_type' => null, 'markup_value' => null]);

        $markup = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.projects.data'))
            ->assertOk()
            ->json('data.0.markup');

        $this->assertStringContainsString('inherits', $markup);
        $this->assertStringContainsString('15%', $markup);
    }

    public function test_the_client_list_shows_both_markup_components(): void
    {
        Client::factory()->for($this->business)->create([
            'default_markup_type' => MarkupType::Hybrid,
            'default_markup_value' => 15,
            'default_markup_fee' => 40,
        ]);

        $markup = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.clients.data'))
            ->assertOk()
            ->json('data.0.markup');

        $this->assertStringContainsString('$40.00', $markup);
        $this->assertStringContainsString('15%', $markup);
    }

    public function test_the_forms_expose_a_fee_field(): void
    {
        $client = Client::factory()->for($this->business)->create();
        Project::factory()->for($client)->create();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.billing.clients.index'))
            ->assertOk()
            ->assertSee('name="default_markup_fee"', false)
            ->assertSee('data-markup-field="fee"', false);

        $this->actingAs($admin)
            ->get(route('admin.billing.projects.index'))
            ->assertOk()
            ->assertSee('name="markup_fee"', false)
            ->assertSee('data-markup-field="percent"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'business_id' => $this->business->id,
            'name' => 'Acme Industries',
            'contact_email' => 'ap@acme.test',
            'billing_currency' => 'CAD',
            'status' => 'active',
            'default_markup_type' => MarkupType::Passthrough->value,
        ], $overrides);
    }
}
