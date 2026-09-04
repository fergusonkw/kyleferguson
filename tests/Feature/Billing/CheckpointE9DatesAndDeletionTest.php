<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment terms, the cheque payee, due dates, and clearing a voided invoice.
 */
final class CheckpointE9DatesAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson',
            'contact_email' => 'kyle@kyleferguson.ca',
            'payment_terms_days' => 14,
            'cheque_payable_to' => null,
        ]);
        $this->client = Client::factory()->for($this->business)->create();
    }

    // ---- Business configuration -------------------------------------------

    public function test_the_business_form_exposes_terms_and_the_cheque_payee(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.businesses.index'))
            ->assertOk()
            ->assertSee('name="payment_terms_days"', false)
            ->assertSee('name="cheque_payable_to"', false);
    }

    public function test_terms_and_payee_round_trip_through_the_form(): void
    {
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.businesses.update', $this->business), $this->businessPayload([
                'payment_terms_days' => 30,
                'cheque_payable_to' => 'Kyle Ferguson',
            ]))
            ->assertOk();

        $fresh = $this->business->fresh();
        $this->assertSame(30, $fresh->payment_terms_days);
        $this->assertSame('Kyle Ferguson', $fresh->cheque_payable_to);

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.businesses.edit', $this->business))
            ->assertOk()
            ->assertJsonPath('business.payment_terms_days', 30)
            ->assertJsonPath('business.cheque_payable_to', 'Kyle Ferguson');
    }

    public function test_terms_may_be_omitted_and_keep_their_default(): void
    {
        $payload = $this->businessPayload();
        unset($payload['payment_terms_days']);

        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.businesses.update', $this->business), $payload)
            ->assertOk();

        $this->assertSame(14, $this->business->fresh()->payment_terms_days);
    }

    public function test_the_cheque_line_appears_once_a_payee_is_set(): void
    {
        $invoice = $this->draft();
        $invoice->update(['business_snapshot' => [
            'name' => 'Kyle Ferguson',
            'contact_email' => 'kyle@kyleferguson.ca',
            'cheque_payable_to' => 'Kyle Ferguson',
        ]]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Cheque payable to:', $html);
    }

    // ---- Due dates ---------------------------------------------------------

    public function test_approval_calculates_the_due_date_from_the_terms(): void
    {
        $this->business->update(['payment_terms_days' => 30]);
        $invoice = $this->billable();

        $approved = app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(30, (int) $approved->issued_on->diffInDays($approved->due_on));
    }

    public function test_a_due_date_set_by_hand_survives_approval(): void
    {
        $invoice = $this->billable();

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.due-date', $invoice), ['due_on' => '2026-12-25'])
            ->assertOk();

        $approved = app(InvoiceApprover::class)->approve($invoice->fresh());

        // Approval must not overwrite a deliberate choice with the default.
        $this->assertSame('2026-12-25', $approved->due_on->toDateString());
    }

    public function test_the_due_date_can_be_changed_after_approval(): void
    {
        $invoice = app(InvoiceApprover::class)->approve($this->billable());

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.due-date', $invoice), [
                'due_on' => now()->addMonths(2)->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(now()->addMonths(2)->toDateString(), $invoice->fresh()->due_on->toDateString());
    }

    public function test_a_due_date_before_the_issue_date_is_refused(): void
    {
        $invoice = app(InvoiceApprover::class)->approve($this->billable());

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.due-date', $invoice), [
                'due_on' => now()->subWeek()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_voided_invoice_cannot_be_re_dated(): void
    {
        $invoice = app(InvoiceApprover::class)->void($this->billable());

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.due-date', $invoice), ['due_on' => '2026-12-25'])
            ->assertStatus(422);
    }

    public function test_setting_a_due_date_needs_the_approve_permission(): void
    {
        $invoice = $this->billable();

        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->patchJson(route('admin.billing.invoices.due-date', $invoice), ['due_on' => '2026-12-25'])
            ->assertForbidden();
    }

    // ---- Deleting a voided invoice ----------------------------------------

    public function test_a_voided_never_sent_invoice_can_be_deleted(): void
    {
        $invoice = app(InvoiceApprover::class)->void($this->billable());

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(0, InvoiceLine::count());
    }

    public function test_deleting_is_recorded_in_the_audit_log(): void
    {
        $invoice = app(InvoiceApprover::class)->void($this->billable());
        $number = $invoice->invoice_number;

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice))
            ->assertOk();

        $entry = AuditLog::query()->where('event', 'invoice_deleted')->firstOrFail();
        $this->assertSame($number, $entry->new_values['invoice_number']);
    }

    public function test_the_number_stays_consumed_after_deletion(): void
    {
        $this->business->update(['invoice_number_prefix' => 'KF-', 'invoice_number_sequence' => 1]);
        $invoice = app(InvoiceApprover::class)->void($this->billable());
        $sequenceAfterIssue = $this->business->fresh()->invoice_number_sequence;

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice))
            ->assertOk();

        // Reusing a number would make the sequence lie about what was issued.
        $this->assertSame($sequenceAfterIssue, $this->business->fresh()->invoice_number_sequence);
    }

    public function test_a_live_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->billable();

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_a_voided_invoice_the_client_received_is_kept(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->markSent($approver->approve($this->billable()));
        $approver->void($invoice);

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice->fresh()))
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_a_voided_invoice_with_payments_is_kept(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->approve($this->billable());
        Payment::factory()->for($invoice)->amount(10.00)->create();
        $approver->void($invoice->fresh());

        $this->actingAs($this->createAdmin())
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice->fresh()))
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_deleting_needs_the_approve_permission(): void
    {
        $invoice = app(InvoiceApprover::class)->void($this->billable());

        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->deleteJson(route('admin.billing.invoices.destroy', $invoice))
            ->assertForbidden();
    }

    public function test_the_detail_page_offers_delete_only_for_a_deletable_invoice(): void
    {
        $live = $this->billable();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.billing.invoices.show', $live))
            ->assertOk()
            ->assertDontSee('id="deleteBtn"', false);

        app(InvoiceApprover::class)->void($live);

        $this->actingAs($admin)
            ->get(route('admin.billing.invoices.show', $live->fresh()))
            ->assertOk()
            ->assertSee('id="deleteBtn"', false);
    }

    private function draft(): Invoice
    {
        return Invoice::factory()->for($this->business)->for($this->client)->create();
    }

    private function billable(): Invoice
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(100)->create();
        $invoice->forceFill(['subtotal' => 100, 'total' => 100])->save();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function businessPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->business->name,
            'contact_email' => $this->business->contact_email,
            'notification_email' => $this->business->notification_email,
            'daily_reminder_time' => '08:00',
            'invoice_number_prefix' => $this->business->invoice_number_prefix,
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD'],
            'fx_source' => 'bank_of_canada',
        ], $overrides);
    }
}
