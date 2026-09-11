<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\ProjectStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\FxRate;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ending a project.
 *
 * `terminated_at` has been on the projects table since Phase 1, fillable and
 * cast, with nothing able to set it and nothing reading it. That made it
 * impossible to retire a project: it stayed in every picker, and — worse — a
 * standing charge attached to it kept billing the client indefinitely.
 */
final class CheckpointF4TerminationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-05 09:00:00');

        $this->business = Business::factory()->create(['supported_currencies' => ['CAD']]);
        $this->client = Client::factory()->for($this->business)->create(['billing_currency' => 'CAD']);
        $this->project = Project::factory()->for($this->client)->create([
            'name' => 'Platform Rebuild',
            'status' => ProjectStatus::Active,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_terminating_a_project_stamps_the_date(): void
    {
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.projects.update', $this->project), $this->payload([
                'status' => ProjectStatus::Terminated->value,
            ]))
            ->assertOk();

        $this->assertSame('2026-09-05', $this->project->fresh()->terminated_at->toDateString());
    }

    public function test_an_explicit_end_date_is_kept(): void
    {
        // A project that ended last month has to be recordable as it happened.
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.projects.update', $this->project), $this->payload([
                'status' => ProjectStatus::Terminated->value,
                'terminated_at' => '2026-06-30',
            ]))
            ->assertOk();

        $this->assertSame('2026-06-30', $this->project->fresh()->terminated_at->toDateString());
    }

    public function test_reviving_a_project_clears_the_date(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Terminated, 'terminated_at' => '2026-06-30'])->save();

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.projects.update', $this->project), $this->payload([
                'status' => ProjectStatus::Active->value,
            ]))
            ->assertOk();

        $this->assertNull($this->project->fresh()->terminated_at);
    }

    public function test_a_date_without_a_terminated_status_is_rejected(): void
    {
        // Billing reads the date, so the two disagreeing would bill in a way
        // the status does not describe.
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.projects.update', $this->project), $this->payload([
                'status' => ProjectStatus::Active->value,
                'terminated_at' => '2026-06-30',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('terminated_at');
    }

    public function test_the_edit_payload_carries_the_date(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Terminated, 'terminated_at' => '2026-06-30'])->save();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.projects.edit', $this->project))
            ->assertOk()
            ->assertJsonPath('project.terminated_at', '2026-06-30');
    }

    public function test_the_form_offers_the_field(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.projects.index'))
            ->assertOk()
            ->assertSee('name="terminated_at"', false);
    }

    public function test_updating_requires_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->putJson(route('admin.billing.projects.update', $this->project), $this->payload())
            ->assertForbidden();
    }

    public function test_a_standing_charge_stops_after_the_project_ends(): void
    {
        $this->recurringTemplate();
        $this->project->forceFill([
            'status' => ProjectStatus::Terminated,
            'terminated_at' => '2026-07-15',
        ])->save();

        $invoice = app(InvoiceBuilder::class)->build($this->client->fresh(), '2026-08');

        $this->assertSame('0.00', $invoice->total);
        $this->assertCount(0, $invoice->lines);
    }

    public function test_the_period_a_project_ended_in_is_still_billed(): void
    {
        // Ended mid-July: July is still owed. Cutting the final invoice short
        // would under-bill work that was actually delivered.
        $this->recurringTemplate();
        $this->project->forceFill([
            'status' => ProjectStatus::Terminated,
            'terminated_at' => '2026-07-15',
        ])->save();

        $invoice = app(InvoiceBuilder::class)->build($this->client->fresh(), '2026-07');

        $this->assertSame('19.00', $invoice->total);
    }

    public function test_a_client_wide_charge_survives_a_projects_termination(): void
    {
        // Nothing about this charge belongs to the project, so ending the
        // project must not silently stop billing the client.
        $this->recurringTemplate(scopeToProject: false);
        $this->project->forceFill([
            'status' => ProjectStatus::Terminated,
            'terminated_at' => '2026-07-15',
        ])->save();

        $invoice = app(InvoiceBuilder::class)->build($this->client->fresh(), '2026-08');

        $this->assertSame('19.00', $invoice->total);
    }

    public function test_an_active_project_keeps_billing(): void
    {
        $this->recurringTemplate();

        $invoice = app(InvoiceBuilder::class)->build($this->client->fresh(), '2026-08');

        $this->assertSame('19.00', $invoice->total);
    }

    private function recurringTemplate(bool $scopeToProject = true): RecurringLineTemplate
    {
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-07')->withRate(1.0)->create();
        FxRate::factory()->pair('USD', 'CAD')->forPeriod('2026-08')->withRate(1.0)->create();

        return RecurringLineTemplate::factory()->create([
            'client_id' => $this->client->id,
            'project_id' => $scopeToProject ? $this->project->id : null,
            'label' => 'Laravel Forge',
            'amount' => '19.00',
            'currency' => 'CAD',
            'active_from' => '2026-01-01',
            'active_to' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'name' => $this->project->name,
            'status' => ProjectStatus::Active->value,
        ], $overrides);
    }
}
