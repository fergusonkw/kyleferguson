<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\LegalEntityType;
use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Invoice;
use App\Models\Billing\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Configuring legal entities, and seeing the threshold on the dashboard.
 *
 * Today the businesses are trade names of one sole proprietor; splitting
 * Tracker Pull into a corporation later means adding a second entity, moving
 * the business onto it, and marking the two as associated.
 */
final class CheckpointG2LegalEntityAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00:00'));
        $this->admin = $this->createAdmin();
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.billing.legal-entities.index'))
            ->assertOk()
            ->assertSee('Legal Entities')
            ->assertSee('Sole proprietorship');
    }

    public function test_an_entity_can_be_created_with_its_associations_stored_both_ways(): void
    {
        $kyle = LegalEntity::factory()->create(['name' => 'Kyle Ferguson']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.billing.legal-entities.store'), [
                'name' => 'Tracker Pull Inc.',
                'entity_type' => LegalEntityType::Corporation->value,
                'threshold_warning_percent' => 75,
                'associate_ids' => [$kyle->id],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $corporation = LegalEntity::where('name', 'Tracker Pull Inc.')->sole();

        $this->assertSame(LegalEntityType::Corporation, $corporation->entity_type);
        $this->assertSame(75, $corporation->threshold_warning_percent);
        $this->assertSame([$kyle->id], $corporation->associates->pluck('id')->all());
        $this->assertSame([$corporation->id], $kyle->fresh()->associates->pluck('id')->all());
        $this->assertTrue(AuditLog::query()->where('auditable_type', LegalEntity::class)->where('event', 'created')->exists());
    }

    public function test_updating_replaces_associations_on_both_sides(): void
    {
        $kyle = LegalEntity::factory()->create();
        $first = LegalEntity::factory()->corporation()->create();
        $second = LegalEntity::factory()->corporation()->create();
        $kyle->syncAssociates([$first->id]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.billing.legal-entities.update', $kyle), [
                'name' => $kyle->name,
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'threshold_warning_percent' => 80,
                'associate_ids' => [$second->id],
            ])
            ->assertOk();

        $this->assertSame([$second->id], $kyle->fresh()->associates->pluck('id')->all());
        $this->assertSame([], $first->fresh()->associates->pluck('id')->all());
        $this->assertSame([$kyle->id], $second->fresh()->associates->pluck('id')->all());
    }

    public function test_leaving_every_association_unticked_clears_them(): void
    {
        $kyle = LegalEntity::factory()->create();
        $corporation = LegalEntity::factory()->corporation()->create();
        $kyle->syncAssociates([$corporation->id]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.billing.legal-entities.update', $kyle), [
                'name' => $kyle->name,
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'threshold_warning_percent' => 80,
            ])
            ->assertOk();

        $this->assertSame(0, $kyle->fresh()->associates()->count());
        $this->assertSame(0, $corporation->fresh()->associates()->count());
    }

    public function test_an_entity_cannot_be_associated_with_itself(): void
    {
        $kyle = LegalEntity::factory()->create();

        $this->actingAs($this->admin)
            ->putJson(route('admin.billing.legal-entities.update', $kyle), [
                'name' => $kyle->name,
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'threshold_warning_percent' => 80,
                'associate_ids' => [$kyle->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['associate_ids.0']);
    }

    public function test_the_registration_date_and_warning_percentage_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.billing.legal-entities.store'), [
                'name' => 'Someone',
                'entity_type' => 'llc',
                'tax_registered_from' => 'not a date',
                'threshold_warning_percent' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entity_type', 'tax_registered_from', 'threshold_warning_percent']);
    }

    public function test_registration_is_recorded_on_the_entity_and_covers_its_businesses(): void
    {
        $kyle = LegalEntity::factory()->create();
        $business = Business::factory()->forLegalEntity($kyle)->create();

        $this->actingAs($this->admin)
            ->putJson(route('admin.billing.legal-entities.update', $kyle), [
                'name' => $kyle->name,
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'tax_registered_from' => '2026-10-01',
                'threshold_warning_percent' => 80,
            ])
            ->assertOk();

        $this->assertTrue($business->fresh()->isTaxRegisteredOn(Carbon::parse('2026-10-01')));
        $this->assertFalse($business->fresh()->isTaxRegisteredOn(Carbon::parse('2026-09-30')));
    }

    public function test_an_entity_with_businesses_cannot_be_deleted(): void
    {
        $kyle = LegalEntity::factory()->create();
        Business::factory()->forLegalEntity($kyle)->create();

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.billing.legal-entities.destroy', $kyle))
            ->assertStatus(422);

        $this->assertModelExists($kyle);
    }

    public function test_an_entity_without_businesses_can_be_deleted_and_its_associations_go_with_it(): void
    {
        $kyle = LegalEntity::factory()->create();
        $spare = LegalEntity::factory()->corporation()->create();
        $kyle->syncAssociates([$spare->id]);

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.billing.legal-entities.destroy', $spare))
            ->assertOk();

        $this->assertModelMissing($spare);
        $this->assertSame(0, $kyle->fresh()->associates()->count());
    }

    public function test_a_user_without_business_permission_cannot_manage_entities(): void
    {
        $user = $this->createUserWithRole(RoleEnum::User->slug());

        $this->actingAs($user)
            ->postJson(route('admin.billing.legal-entities.store'), [
                'name' => 'Someone',
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'threshold_warning_percent' => 80,
            ])
            ->assertForbidden();
    }

    public function test_the_data_endpoint_lists_businesses_and_associates(): void
    {
        $kyle = LegalEntity::factory()->create(['name' => 'Kyle Ferguson']);
        $corporation = LegalEntity::factory()->corporation()->create(['name' => 'Tracker Pull Inc.']);
        $kyle->syncAssociates([$corporation->id]);
        Business::factory()->forLegalEntity($kyle)->create(['name' => 'Kyle Ferguson Consulting']);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('admin.billing.legal-entities.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->json('data'))
            ->keyBy('name');

        $this->assertSame('Kyle Ferguson Consulting', $rows['Kyle Ferguson']['businesses']);
        $this->assertSame('Tracker Pull Inc.', $rows['Kyle Ferguson']['associates']);
        $this->assertSame('Kyle Ferguson', $rows['Tracker Pull Inc.']['associates']);
    }

    public function test_a_business_must_name_its_legal_entity(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.billing.businesses.store'), [
                'name' => 'Nameless',
                'contact_email' => 'a@example.test',
                'notification_email' => 'n@example.test',
                'invoice_number_prefix' => 'N-',
                'default_currency' => 'CAD',
                'supported_currencies' => ['CAD'],
                'fx_source' => 'bank_of_canada',
                'daily_reminder_time' => '08:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_entity_id']);
    }

    public function test_a_business_can_be_moved_to_another_entity(): void
    {
        $kyle = LegalEntity::factory()->create();
        $corporation = LegalEntity::factory()->corporation()->create();
        $business = Business::factory()->forLegalEntity($kyle)->create();

        $this->actingAs($this->admin)
            ->putJson(route('admin.billing.businesses.update', $business), [
                'legal_entity_id' => $corporation->id,
                'name' => $business->name,
                'contact_email' => $business->contact_email,
                'notification_email' => $business->notification_email,
                'invoice_number_prefix' => $business->invoice_number_prefix,
                'default_currency' => 'CAD',
                'supported_currencies' => ['CAD'],
                'fx_source' => 'bank_of_canada',
                'daily_reminder_time' => '08:00',
            ])
            ->assertOk();

        $this->assertSame($corporation->id, $business->fresh()->legal_entity_id);
    }

    public function test_the_businesses_page_offers_the_entities_and_the_table_names_them(): void
    {
        $kyle = LegalEntity::factory()->create(['name' => 'Kyle Ferguson']);
        Business::factory()->forLegalEntity($kyle)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.billing.businesses.index'))
            ->assertOk()
            ->assertSee('Kyle Ferguson');

        $this->actingAs($this->admin)
            ->getJson(route('admin.billing.businesses.data', ['draw' => 1, 'length' => 50]))
            ->assertOk()
            ->assertJsonPath('data.0.legal_entity', fn (string $cell): bool => str_contains($cell, 'Kyle Ferguson'));
    }

    public function test_the_dashboard_shows_the_threshold_for_the_current_businesss_entity(): void
    {
        $business = $this->currentBusiness();
        $this->supply($business, '2026-08-01', '12345.67');

        $this->actingAs($this->admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('GST/HST threshold')
            ->assertSee('$12,345.67')
            ->assertSee('Below threshold');
    }

    public function test_the_dashboard_warns_when_the_threshold_is_near(): void
    {
        $business = $this->currentBusiness();
        $this->supply($business, '2026-08-01', '26000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Approaching threshold')
            ->assertSee('Taxable supplies are at 86.7% of the $30,000 threshold', false);
    }

    public function test_the_dashboard_shows_a_registered_entity_as_registered(): void
    {
        $business = $this->currentBusiness(LegalEntity::factory()->taxRegistered(Carbon::parse('2026-01-01'))->create());

        $this->actingAs($this->admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Registered for GST/HST')
            ->assertSee('January 1, 2026');
    }

    public function test_the_dashboard_says_so_when_a_business_has_no_legal_entity(): void
    {
        $business = $this->currentBusiness();
        $business->forceFill(['legal_entity_id' => null])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('has no legal entity, so the GST/HST threshold is not being tracked');
    }

    private function currentBusiness(?LegalEntity $entity = null): Business
    {
        $business = Business::factory()->forLegalEntity($entity ?? LegalEntity::factory()->create())->create();
        $this->actingAs($this->admin)->post(route('admin.billing.switch', $business));

        return $business;
    }

    private function supply(Business $business, string $issuedOn, string $valueCad): Invoice
    {
        return Invoice::factory()->for($business)->status(InvoiceStatus::Sent)->create([
            'issued_on' => $issuedOn,
            'supply_value_cad' => $valueCad,
        ]);
    }
}
