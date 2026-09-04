<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Models\Billing\Project;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin surface: generating a draft, reviewing it, approving, and
 * recording payments — plus the permission split between editing a draft and
 * issuing one.
 */
final class CheckpointE4InvoiceUiTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::factory()->create([
            'invoice_number_prefix' => 'KF-',
            'invoice_number_sequence' => 1,
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Passthrough,
        ]);
        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(2.0)->create();
    }

    public function test_invoice_list_requires_billing_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.invoices.index'))
            ->assertForbidden();
    }

    public function test_invoice_list_loads_for_admin(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.index'))
            ->assertOk()
            ->assertSee('Generate Draft');
    }

    public function test_admin_can_generate_a_draft_from_the_ui(): void
    {
        $this->seedCosts(50.00);

        $response = $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.generate'), [
                'client_id' => $this->client->id,
                'period' => self::PERIOD,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('KF-00001', $invoice->invoice_number);
        $this->assertSame('100.00', $invoice->total);
        $response->assertJsonPath('redirect', route('admin.billing.invoices.show', $invoice));
    }

    public function test_generating_requires_the_manage_invoices_permission(): void
    {
        $viewer = $this->userWithBillingPermissions([Permission::ViewBilling]);

        $this->actingAs($viewer)
            ->postJson(route('admin.billing.invoices.generate'), [
                'client_id' => $this->client->id,
                'period' => self::PERIOD,
            ])
            ->assertForbidden();
    }

    public function test_generating_rejects_a_malformed_period(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.generate'), [
                'client_id' => $this->client->id,
                'period' => 'August',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    public function test_a_generation_failure_surfaces_as_a_message_not_a_crash(): void
    {
        Http::fake(['*/observations/*' => Http::response(['observations' => []])]);
        FxRate::query()->delete();

        \App\Models\Billing\RecurringLineTemplate::factory()->for($this->client)
            ->amount(19.00, 'USD')->window('2026-01-01')->create(['label' => 'Domain renewal']);

        // No rate is resolvable for USD→CAD, so the builder refuses rather
        // than inventing one; the operator sees why.
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.generate'), [
                'client_id' => $this->client->id,
                'period' => self::PERIOD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_the_data_endpoint_scopes_to_the_current_business(): void
    {
        $mine = $this->invoice();
        $other = Business::factory()->create(['name' => 'Z Business']);
        Invoice::factory()->for($other)->for(Client::factory()->for($other))->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.invoices.data'))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.invoice_number', $mine->invoice_number);
    }

    public function test_the_data_endpoint_filters_by_status_and_period(): void
    {
        $this->invoice(period: '2026-08', status: InvoiceStatus::Draft);
        $this->invoice(period: '2026-07', status: InvoiceStatus::Sent);

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.billing.invoices.data', ['status' => 'sent']))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.billing.invoices.data', ['period' => '2026-08']))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);
    }

    public function test_detail_page_shows_lines_totals_and_status(): void
    {
        $invoice = $this->invoice();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)
            ->amount(120.00)->create(['label' => 'Hosting — Acme']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Hosting — Acme')
            ->assertSee('Acme Industries')
            ->assertSee('Draft');
    }

    public function test_an_operator_can_add_a_manual_line(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '250.00',
            ])
            ->assertOk();

        $this->assertSame('250.00', $invoice->fresh()->total);
    }

    public function test_a_discount_is_stored_as_a_reduction(): void
    {
        $invoice = $this->invoice();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(100.00)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Goodwill',
                'line_type' => InvoiceLineType::Discount->value,
                'amount' => '25.00',
            ])
            ->assertOk();

        $this->assertSame('75.00', $invoice->fresh()->total);
    }

    public function test_hosting_lines_cannot_be_added_by_hand(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Sneaky hosting',
                'line_type' => InvoiceLineType::Hosting->value,
                'amount' => '10.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['line_type']);
    }

    public function test_a_zero_amount_line_is_rejected(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Nothing',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_lines_cannot_be_added_once_the_invoice_leaves_draft(): void
    {
        $invoice = $this->invoice(status: InvoiceStatus::Sent);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Too late',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '10.00',
            ])
            ->assertForbidden();
    }

    public function test_a_derived_line_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount(50)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.lines.destroy', [$invoice, $line]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
    }

    public function test_a_manual_line_can_be_deleted(): void
    {
        $invoice = $this->invoice();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(50)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.lines.destroy', [$invoice, $line]))
            ->assertOk();

        $this->assertDatabaseMissing('invoice_lines', ['id' => $line->id]);
        $this->assertSame('0.00', $invoice->fresh()->total);
    }

    public function test_a_line_belonging_to_another_invoice_is_refused(): void
    {
        $invoice = $this->invoice();
        $other = $this->invoice(period: '2026-07');
        $line = InvoiceLine::factory()->for($other)->ofType(InvoiceLineType::Manual)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.lines.destroy', [$invoice, $line]))
            ->assertNotFound();
    }

    public function test_approving_requires_the_approve_permission(): void
    {
        $invoice = $this->invoice();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(50)->create();

        $editor = $this->userWithBillingPermissions([Permission::ViewBilling, Permission::ManageInvoices]);

        $this->actingAs($editor)
            ->postJson(route('admin.billing.invoices.approve', $invoice))
            ->assertForbidden();
    }

    public function test_an_empty_draft_refuses_approval_with_a_readable_message(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.approve', $invoice))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_marking_sent_is_refused_from_draft(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_invoice_can_be_voided_with_a_reason(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.void', $invoice), ['reason' => 'Duplicate'])
            ->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertStringContainsString('Duplicate', $fresh->notes);
    }

    public function test_rebuilding_a_draft_pulls_in_new_costs(): void
    {
        $this->seedCosts(50.00);
        $admin = $this->createAdmin();

        $this->actingAs($admin)->postJson(route('admin.billing.invoices.generate'), [
            'client_id' => $this->client->id,
            'period' => self::PERIOD,
        ])->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        $this->seedCosts(25.00, reference: 'later');

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.regenerate', $invoice))
            ->assertOk();

        $this->assertSame('150.00', $invoice->fresh()->total);
    }

    public function test_a_payment_can_be_recorded_from_the_ui(): void
    {
        $invoice = $this->invoice(status: InvoiceStatus::Sent, total: 100.00);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.payments.store', $invoice), [
                'amount' => '40.00',
                'method' => PaymentMethod::ETransfer->value,
                'received_at' => now()->toDateString(),
                'reference' => 'ETR-1',
            ])
            ->assertOk()
            ->assertJsonPath('status', InvoiceStatus::PartiallyPaid->value)
            ->assertJsonPath('balance', '60.00');
    }

    public function test_a_future_dated_payment_is_rejected(): void
    {
        $invoice = $this->invoice(status: InvoiceStatus::Sent, total: 100.00);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.payments.store', $invoice), [
                'amount' => '40.00',
                'method' => PaymentMethod::Cash->value,
                'received_at' => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['received_at']);
    }

    public function test_a_payment_against_a_draft_is_refused(): void
    {
        $invoice = $this->invoice(total: 100.00);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.payments.store', $invoice), [
                'amount' => '40.00',
                'method' => PaymentMethod::Cash->value,
                'received_at' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_voiding_a_payment_recalculates_the_status(): void
    {
        $invoice = $this->invoice(status: InvoiceStatus::Sent, total: 100.00);
        $payment = Payment::factory()->for($invoice)->amount(100.00)->create();
        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.payments.destroy', [$invoice, $payment]))
            ->assertOk();

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_a_payment_from_another_invoice_is_refused(): void
    {
        $invoice = $this->invoice(status: InvoiceStatus::Sent);
        $other = $this->invoice(period: '2026-07', status: InvoiceStatus::Sent);
        $payment = Payment::factory()->for($other)->amount(10.00)->create();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.payments.destroy', [$invoice, $payment]))
            ->assertNotFound();
    }

    public function test_the_preview_renders_the_client_facing_document(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['business_snapshot' => ['name' => 'Kyle Ferguson'], 'client_snapshot' => ['name' => 'Acme Industries']]);
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount(120)->create(['label' => 'Hosting — Acme']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.preview', $invoice))
            ->assertOk()
            ->assertSee('Hosting — Acme')
            ->assertSee($invoice->invoice_number);
    }

    public function test_the_pdf_route_is_gated_on_billing_access(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.invoices.pdf', $invoice))
            ->assertForbidden();
    }

    public function test_invoices_appear_in_the_sidebar(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee(route('admin.billing.invoices.index'));
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWithBillingPermissions(array $permissions): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scoped', 'slug' => 'scoped-'.uniqid()]);

        $ids = PermissionModel::query()
            ->whereIn('slug', array_map(fn (Permission $p): string => $p->value, $permissions))
            ->pluck('id');

        $role->permissions()->sync($ids);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function invoice(
        string $period = self::PERIOD,
        InvoiceStatus $status = InvoiceStatus::Draft,
        float $total = 0.0,
    ): Invoice {
        return Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod($period)->status($status)->withTotal($total)->create();
    }

    private function seedCosts(float $usd, ?string $reference = null): void
    {
        $project = Project::query()->where('client_id', $this->client->id)->first()
            ?? Project::factory()->for($this->client)->create(['name' => 'Acme']);

        // Providers are unique per business + slug, so reuse rather than
        // creating a second one on a repeat call.
        $provider = CostProvider::query()->where('business_id', $this->business->id)->first()
            ?? CostProvider::factory()->for($this->business)->create();

        CostLineItem::factory()
            ->for($provider)
            ->forPeriod(self::PERIOD)
            ->ofCategory(CostCategory::Compute)
            ->usd($usd)
            ->attributedTo($project)
            ->create(['source_reference' => $reference]);
    }
}
