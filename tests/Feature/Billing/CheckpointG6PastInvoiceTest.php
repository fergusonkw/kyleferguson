<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Permission;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\SmallSupplierThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * Invoices the client received before this system kept the books, entered
 * for the record.
 *
 * One takes the next number like any other — the originals were numbered with
 * this system in mind — but is written by hand from the original, never built
 * from costs, and recorded as issued on its real date: no email, and counted
 * toward the GST/HST threshold in the quarter it actually happened.
 *
 * "Today" is September 15, 2026 throughout.
 */
final class CheckpointG6PastInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Pdf::fake();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-15 09:00:00'));

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson',
            'invoice_number_prefix' => 'KF-',
            'invoice_number_sequence' => 7,
            'payment_terms_days' => 30,
            'supported_currencies' => ['CAD'],
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'billing_currency' => 'CAD',
        ]);

        foreach (['2019-03', '2026-02', '2026-08', '2026-09'] as $period) {
            FxRate::factory()->pair('USD', 'CAD')->forPeriod($period)->withRate(1.35)->create();
        }
    }

    // ---- Starting one ----------------------------------------------------------

    public function test_a_past_invoice_takes_the_next_number_and_starts_empty(): void
    {
        $this->start('2026-02')
            ->assertOk()
            ->assertJsonPath('message', 'KF-00007 started. Add its lines as they were on the original, then record it as issued.');

        $invoice = Invoice::query()->sole();

        $this->assertSame('KF-00007', $invoice->invoice_number);
        $this->assertSame(8, $this->business->fresh()->invoice_number_sequence);
        $this->assertTrue($invoice->is_historical);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(0, $invoice->lines()->count());
    }

    public function test_nothing_on_file_for_the_period_is_derived_into_it(): void
    {
        // Costs and a recurring item that were live in February. The original
        // is the authority on what the client was billed, not these.
        $project = Project::factory()->for($this->client)->create();
        CostLineItem::factory()->for(CostProvider::factory()->for($this->business))
            ->forPeriod('2026-02')->usd(80.00)->attributedTo($project)->create();
        RecurringLineTemplate::factory()->for($this->client)->amount(25.00)->window('2025-01-01')->create();

        $this->start('2026-02')->assertOk();

        $this->assertSame(0, Invoice::query()->sole()->lines()->count());
    }

    public function test_any_month_already_started_will_do_but_not_one_still_to_come(): void
    {
        $this->start('2019-03')->assertOk();

        $this->start('2026-10')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A past invoice has to be for a month that has already started.');

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_client_keeps_one_invoice_per_month(): void
    {
        Invoice::factory()->for($this->business)->for($this->client)->forPeriod('2026-02')->sent()
            ->create(['invoice_number' => 'KF-00002']);

        $this->start('2026-02')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Acme Industries already has invoice KF-00002 for February 2026. A client has one invoice per period.');
    }

    public function test_starting_one_takes_the_permission_to_create_invoices(): void
    {
        $this->actingAs($this->userWith([Permission::ViewBilling]))
            ->postJson(route('admin.billing.invoices.past.start'), ['client_id' => $this->client->id, 'period' => '2026-02'])
            ->assertForbidden();
    }

    // ---- Keeping it hand-written -----------------------------------------------

    public function test_it_is_never_rebuilt_from_costs(): void
    {
        $invoice = $this->pastDraft();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.regenerate', $invoice))
            ->assertStatus(422)
            ->assertJsonPath('message', 'KF-00007 is a past invoice entered by hand from the original, so there is nothing to build it from.');

        $this->actingAs($admin)
            ->postJson(route('admin.billing.invoices.generate'), ['client_id' => $this->client->id, 'period' => '2026-02'])
            ->assertStatus(422);

        $this->assertSame(1, $invoice->lines()->count());
    }

    public function test_it_cannot_be_approved_into_todays_date(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.approve', $this->pastDraft()))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice KF-00007 is a past invoice — record it as issued on its original date instead of approving it.');
    }

    public function test_lines_are_added_the_usual_way(): void
    {
        $invoice = $this->pastDraft(withLine: false);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Website rebuild',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '1200.00',
            ])
            ->assertOk();

        $this->assertSame('1200.00', $invoice->fresh()->total);
    }

    // ---- Recording it ----------------------------------------------------------

    public function test_it_is_recorded_on_its_original_dates_and_nothing_is_emailed(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-02-10', 'due_on' => '2026-03-12'])
            ->assertOk()
            ->assertJsonPath('message', 'KF-00007 recorded as issued February 10, 2026. Nothing was emailed.');

        $invoice->refresh();

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('2026-02-10', $invoice->issued_on->toDateString());
        $this->assertSame('2026-03-12', $invoice->due_on->toDateString());
        $this->assertSame('2026-02-10 00:00:00', $invoice->sent_at->toDateTimeString());
        // When it entered the books — the one thing that did happen today.
        $this->assertTrue($invoice->approved_at->isSameDay(now()));

        Mail::assertNothingSent();
    }

    public function test_the_copy_on_record_carries_the_original_dates(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-02-10'])->assertOk();

        $document = $invoice->fresh()->issuedDocument;

        $this->assertSame(InvoiceDocumentReason::Recorded, $document->reason);
        $this->assertStringContainsString('February 10, 2026', $document->html);
    }

    public function test_the_due_date_defaults_to_the_terms_counted_from_the_issue_date(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-02-10'])->assertOk();

        $this->assertSame('2026-03-12', $invoice->fresh()->due_on->toDateString());
    }

    public function test_paid_in_full_records_the_payment_on_the_day_it_arrived(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, [
            'issued_on' => '2026-02-10',
            'paid' => '1',
            'paid_on' => '2026-02-20',
            'method' => PaymentMethod::ETransfer->value,
            'reference' => 'ETR-4471',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'KF-00007 recorded as issued February 10, 2026 and paid. Nothing was emailed.');

        $invoice->refresh();
        $payment = $invoice->payments()->sole();

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('450.00', $payment->amount);
        $this->assertSame('2026-02-20', $payment->received_at->toDateString());
        $this->assertSame(PaymentMethod::ETransfer, $payment->method);
        $this->assertSame('ETR-4471', $payment->reference);
    }

    public function test_one_still_owed_is_left_for_payments_to_come(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-08-05', 'paid' => '0'])->assertOk();

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_the_threshold_counts_it_in_the_quarter_it_was_issued(): void
    {
        // Approving it today would have put February's supply into Q3.
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-02-10'])->assertOk();

        $assessment = app(SmallSupplierThreshold::class)->assess($this->business->legalEntity);
        $quarters = collect($assessment->quarters)->pluck('total', 'label');

        $this->assertSame('450.00', $invoice->fresh()->supply_value_cad);
        $this->assertSame('450.00', $quarters['Q1 2026']);
        $this->assertSame('0.00', $assessment->currentQuarterTotal);
    }

    public function test_a_payment_that_fails_leaves_nothing_half_entered(): void
    {
        // A zero total cannot take a payment, so the payment step fails after
        // the invoice was recorded — and the recording goes with it.
        $invoice = $this->pastDraft(amount: 0.00);

        $this->record($invoice, [
            'issued_on' => '2026-02-10',
            'paid' => '1',
            'paid_on' => '2026-02-20',
            'method' => PaymentMethod::Cheque->value,
        ])->assertStatus(422);

        $invoice->refresh();

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->issued_on);
        $this->assertSame(0, $invoice->documents()->count());
    }

    // ---- Refusals --------------------------------------------------------------

    public function test_it_needs_lines_before_it_can_be_recorded(): void
    {
        $this->record($this->pastDraft(withLine: false), ['issued_on' => '2026-02-10'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice KF-00007 has no lines yet — add them as they appeared on the original.');
    }

    public function test_dates_that_could_not_have_been_are_refused(): void
    {
        $invoice = $this->pastDraft();

        $this->record($invoice, ['issued_on' => '2026-09-16'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['issued_on' => 'A past invoice cannot have been issued in the future.']);

        $this->record($invoice, ['issued_on' => '2026-02-10', 'due_on' => '2026-02-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_on' => 'The due date cannot fall before the issue date.']);

        $this->record($invoice, ['issued_on' => '2026-02-10', 'paid' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'paid_on' => 'Enter the date the payment arrived.',
                'method' => 'Choose how the client paid.',
            ]);

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_only_a_past_invoice_still_in_draft_can_be_recorded(): void
    {
        $ordinary = Invoice::factory()->for($this->business)->for($this->client)->forPeriod('2026-08')->create(['invoice_number' => 'KF-00003']);
        InvoiceLine::factory()->for($ordinary)->ofType(InvoiceLineType::Manual)->amount(100)->create();

        $this->record($ordinary, ['issued_on' => '2026-08-10'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice KF-00003 is not a past invoice waiting to be recorded.');

        $past = $this->pastDraft();
        $this->record($past, ['issued_on' => '2026-02-10'])->assertOk();

        $this->record($past, ['issued_on' => '2026-02-11'])->assertStatus(422);
        $this->assertSame('2026-02-10', $past->fresh()->issued_on->toDateString());
    }

    public function test_recording_takes_the_issuing_permission(): void
    {
        $this->actingAs($this->userWith([Permission::ViewBilling, Permission::ManageInvoices]))
            ->postJson(route('admin.billing.invoices.past.record', $this->pastDraft()), ['issued_on' => '2026-02-10'])
            ->assertForbidden();
    }

    // ---- The pages -------------------------------------------------------------

    public function test_the_invoice_list_offers_it_with_the_number_it_will_take(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.index'))
            ->assertOk()
            ->assertSee('Record Past Invoice')
            ->assertSee('invoice number — KF-00007 — and starts empty');
    }

    public function test_a_past_draft_offers_recording_in_place_of_approval(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $this->pastDraft()))
            ->assertOk()
            ->assertSee('Past invoice')
            ->assertSee('A past invoice, being entered for the record')
            ->assertSee('id="recordPastBtn"', false)
            ->assertSee('id="recordPastForm"', false)
            ->assertDontSee('id="approveBtn"', false)
            ->assertDontSee('id="regenerateBtn"', false)
            ->assertDontSee('id="clientMessageForm"', false)
            ->assertDontSee('id="saveDueDateBtn"', false);
    }

    public function test_a_recorded_past_invoice_stays_marked_in_the_list(): void
    {
        $invoice = $this->pastDraft();
        $this->record($invoice, ['issued_on' => '2026-02-10'])->assertOk();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Past invoice')
            ->assertDontSee('id="recordPastBtn"', false);

        $this->actingAs($this->createAdmin())
            ->withSession(['billing.current_business_id' => $this->business->id])
            ->getJson(route('admin.billing.invoices.data'))
            ->assertOk()
            ->assertSee('Past');
    }

    private function start(string $period): TestResponse
    {
        return $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.past.start'), [
                'client_id' => $this->client->id,
                'period' => $period,
            ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function record(Invoice $invoice, array $fields): TestResponse
    {
        return $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.past.record', $invoice), $fields);
    }

    /**
     * February's invoice, started and given its one line, as the operator
     * would leave it before recording.
     */
    private function pastDraft(bool $withLine = true, float $amount = 450.00): Invoice
    {
        $invoice = app(InvoiceBuilder::class)->startPast($this->client, '2026-02');

        if ($withLine) {
            InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)
                ->amount($amount)->create(['label' => 'Website work — February']);
            app(InvoiceBuilder::class)->recalculateTotals($invoice->fresh());
        }

        return $invoice->fresh();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
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
}
