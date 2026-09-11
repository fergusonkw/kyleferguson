<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\RecurringCadence;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin surface for standing charges.
 *
 * InvoiceBuilder has supported these since Phase 3 opened, but there was no
 * way to create one — the engine was built and unreachable.
 */
final class CheckpointF1RecurringUiTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['supported_currencies' => ['CAD', 'USD']]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'billing_currency' => 'CAD',
        ]);
        $this->project = Project::factory()->for($this->client)->create(['name' => 'Platform Rebuild']);
    }

    public function test_the_page_requires_billing_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.recurring-lines.index'))
            ->assertForbidden();
    }

    public function test_the_page_loads_for_admin(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.recurring-lines.index'))
            ->assertOk()
            ->assertSee('Add Recurring Item');
    }

    public function test_an_item_can_be_attached_to_a_client(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $template = RecurringLineTemplate::query()->firstOrFail();

        $this->assertSame($this->client->id, $template->client_id);
        $this->assertNull($template->project_id);
        $this->assertSame('Laravel Forge', $template->label);
        $this->assertSame('CAD', $template->currency);
    }

    public function test_an_item_can_be_narrowed_to_one_project(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'project_id' => $this->project->id,
            ]))
            ->assertOk();

        $template = RecurringLineTemplate::query()->firstOrFail();

        $this->assertSame($this->project->id, $template->project_id);
        $this->assertNull($template->client_id);
    }

    public function test_an_item_must_bill_to_something(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_an_item_cannot_bill_to_both_a_client_and_a_project(): void
    {
        // Both would make it ambiguous which invoices it lands on.
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
                'project_id' => $this->project->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_a_zero_amount_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
                'amount' => '0',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
                'active_from' => '2026-06-01',
                'active_to' => '2026-01-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['active_to']);
    }

    public function test_creating_requires_the_manage_invoices_permission(): void
    {
        $viewer = $this->userWithPermissions([Permission::ViewBilling]);

        $this->actingAs($viewer)
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
            ]))
            ->assertForbidden();
    }

    public function test_the_list_is_scoped_to_the_current_business(): void
    {
        RecurringLineTemplate::factory()->for($this->client)->create(['label' => 'Mine']);

        $otherBusiness = Business::factory()->create(['name' => 'Z Business']);
        RecurringLineTemplate::factory()
            ->for(Client::factory()->for($otherBusiness))
            ->create(['label' => 'Theirs']);

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.recurring-lines.data'))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.label', 'Mine');
    }

    public function test_a_project_scoped_item_is_listed_under_its_business(): void
    {
        RecurringLineTemplate::factory()->forProject($this->project)->create(['label' => 'Project item']);

        $response = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.recurring-lines.data'))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);

        $this->assertStringContainsString('Platform Rebuild', $response->json('data.0.applies_to'));
    }

    public function test_the_list_says_whether_an_item_is_currently_billing(): void
    {
        RecurringLineTemplate::factory()->for($this->client)
            ->window(now()->subYear()->toDateString())->create(['label' => 'Live']);
        RecurringLineTemplate::factory()->for($this->client)
            ->window(now()->addMonths(2)->toDateString())->create(['label' => 'Future']);
        RecurringLineTemplate::factory()->for($this->client)
            ->window(now()->subYears(2)->toDateString(), now()->subYear()->toDateString())
            ->create(['label' => 'Finished']);

        $rows = collect($this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.recurring-lines.data'))
            ->assertOk()
            ->json('data'))->keyBy('label');

        $this->assertStringContainsString('Active', $rows['Live']['status']);
        $this->assertStringContainsString('Scheduled', $rows['Future']['status']);
        $this->assertStringContainsString('Ended', $rows['Finished']['status']);
    }

    public function test_an_item_can_be_edited(): void
    {
        $template = RecurringLineTemplate::factory()->for($this->client)->create(['amount' => 19.00]);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.recurring-lines.update', $template), $this->payload([
                'client_id' => $this->client->id,
                'amount' => '29.00',
                'label' => 'Laravel Forge (business plan)',
            ]))
            ->assertOk();

        $fresh = $template->fresh();
        $this->assertSame('29.00', $fresh->amount);
        $this->assertSame('Laravel Forge (business plan)', $fresh->label);
    }

    public function test_editing_what_a_client_is_charged_is_audited(): void
    {
        $template = RecurringLineTemplate::factory()->for($this->client)->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.recurring-lines.update', $template), $this->payload([
                'client_id' => $this->client->id,
                'amount' => '99.00',
            ]))
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', 'recurring_line_changed')->count());
    }

    public function test_an_item_can_be_moved_from_a_client_to_a_project(): void
    {
        $template = RecurringLineTemplate::factory()->for($this->client)->create();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.recurring-lines.update', $template), $this->payload([
                'project_id' => $this->project->id,
            ]))
            ->assertOk();

        $fresh = $template->fresh();
        $this->assertSame($this->project->id, $fresh->project_id);
        $this->assertNull($fresh->client_id);
    }

    public function test_the_edit_payload_round_trips(): void
    {
        $template = RecurringLineTemplate::factory()->forProject($this->project)
            ->cadence(RecurringCadence::Quarterly)
            ->window('2026-03-01', '2027-03-01')
            ->create(['label' => 'Quarterly retainer', 'amount' => 750.00, 'currency' => 'USD']);

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.recurring-lines.edit', $template))
            ->assertOk()
            ->assertJsonPath('template.label', 'Quarterly retainer')
            ->assertJsonPath('template.cadence', 'quarterly')
            ->assertJsonPath('template.currency', 'USD')
            ->assertJsonPath('template.project_id', $this->project->id)
            ->assertJsonPath('template.active_from', '2026-03-01')
            ->assertJsonPath('template.active_to', '2027-03-01');
    }

    public function test_an_item_can_be_removed(): void
    {
        $template = RecurringLineTemplate::factory()->for($this->client)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.recurring-lines.destroy', $template))
            ->assertOk();

        $this->assertDatabaseMissing('recurring_line_templates', ['id' => $template->id]);
    }

    public function test_the_target_list_offers_clients_and_their_projects(): void
    {
        Project::factory()->for($this->client)->create(['name' => 'Second Project']);

        $response = $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.recurring-lines.targets'))
            ->assertOk()
            ->assertJsonPath('clients.0.name', 'Acme Industries')
            ->assertJsonPath('clients.0.currency', 'CAD');

        $this->assertCount(2, $response->json('clients.0.projects'));
    }

    public function test_a_created_item_reaches_the_next_draft(): void
    {
        // The whole point: the builder already supported these, so creating one
        // through the UI must actually change what a client is invoiced.
        \App\Models\Billing\FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->withRate(1.0)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.recurring-lines.store'), $this->payload([
                'client_id' => $this->client->id,
                'amount' => '19.00',
                'active_from' => '2026-01-01',
            ]))
            ->assertOk();

        $invoice = app(\App\Services\Billing\InvoiceBuilder::class)->build($this->client, '2026-08');

        $this->assertSame('19.00', $invoice->total);
        $this->assertSame('Laravel Forge', $invoice->lines->first()->label);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scoped', 'slug' => 'scoped-'.uniqid()]);

        $role->permissions()->sync(
            PermissionModel::query()
                ->whereIn('slug', array_map(fn (Permission $p): string => $p->value, $permissions))
                ->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Laravel Forge',
            'amount' => '19.00',
            'currency' => 'CAD',
            'cadence' => RecurringCadence::Monthly->value,
            'active_from' => '2026-01-01',
        ], $overrides);
    }
}
