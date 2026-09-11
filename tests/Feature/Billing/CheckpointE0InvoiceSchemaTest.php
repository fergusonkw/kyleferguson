<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\RecurringCadence;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Models\User;
use App\Services\Billing\InvoiceNumberAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 3 invoicing schema: the invariants that keep an issued invoice
 * reproducible and its numbering trustworthy.
 */
final class CheckpointE0InvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_number_is_unique_within_a_business(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        Invoice::factory()->for($business)->for($client)->create(['invoice_number' => 'INV-00001']);

        $this->expectException(QueryException::class);

        Invoice::factory()->for($business)->for($client)
            ->forPeriod('2026-07')
            ->create(['invoice_number' => 'INV-00001']);
    }

    public function test_the_same_invoice_number_may_exist_in_another_business(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();

        Invoice::factory()->for($a)->for(Client::factory()->for($a))->create(['invoice_number' => 'INV-00001']);
        Invoice::factory()->for($b)->for(Client::factory()->for($b))->create(['invoice_number' => 'INV-00001']);

        $this->assertSame(2, Invoice::query()->where('invoice_number', 'INV-00001')->count());
    }

    public function test_a_client_cannot_have_two_invoices_for_the_same_period(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        Invoice::factory()->for($business)->for($client)->forPeriod('2026-08')->create();

        $this->expectException(QueryException::class);

        Invoice::factory()->for($business)->for($client)->forPeriod('2026-08')->create();
    }

    public function test_hosted_view_tokens_are_unique_and_long(): void
    {
        $token = Invoice::generateHostedViewToken();

        $this->assertSame(64, mb_strlen($token));
        $this->assertNotSame($token, Invoice::generateHostedViewToken());
    }

    public function test_allocator_issues_sequential_padded_numbers(): void
    {
        $business = Business::factory()->create([
            'invoice_number_prefix' => 'KF-',
            'invoice_number_sequence' => 1,
        ]);
        $allocator = app(InvoiceNumberAllocator::class);

        $this->assertSame('KF-00001', $allocator->allocate($business));
        $this->assertSame('KF-00002', $allocator->allocate($business));
        $this->assertSame('KF-00003', $allocator->allocate($business));
        $this->assertSame(4, $business->fresh()->invoice_number_sequence);
    }

    public function test_allocator_sequences_are_independent_per_business(): void
    {
        $a = Business::factory()->create(['invoice_number_prefix' => 'A-', 'invoice_number_sequence' => 1]);
        $b = Business::factory()->create(['invoice_number_prefix' => 'B-', 'invoice_number_sequence' => 1]);
        $allocator = app(InvoiceNumberAllocator::class);

        $allocator->allocate($a);
        $allocator->allocate($a);

        $this->assertSame('B-00001', $allocator->allocate($b));
    }

    public function test_peek_does_not_consume_a_number(): void
    {
        $business = Business::factory()->create(['invoice_number_prefix' => 'KF-', 'invoice_number_sequence' => 7]);
        $allocator = app(InvoiceNumberAllocator::class);

        $this->assertSame('KF-00007', $allocator->peek($business));
        $this->assertSame(7, $business->fresh()->invoice_number_sequence);
        $this->assertSame('KF-00007', $allocator->allocate($business));
    }

    public function test_status_transitions_follow_the_approval_workflow(): void
    {
        $this->assertTrue(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Approved));
        $this->assertTrue(InvoiceStatus::Approved->canTransitionTo(InvoiceStatus::Sent));

        $this->assertFalse(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Sent));
        $this->assertFalse(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Paid));
        $this->assertFalse(InvoiceStatus::Void->canTransitionTo(InvoiceStatus::Draft));
        $this->assertFalse(InvoiceStatus::Paid->canTransitionTo(InvoiceStatus::Draft));
    }

    public function test_payment_statuses_are_not_operator_choosable(): void
    {
        // They are computed from the payment total, so no operator transition
        // may reach them — only PaymentRecorder can, via isPaymentTracked().
        $this->assertFalse(InvoiceStatus::Sent->canTransitionTo(InvoiceStatus::Paid));
        $this->assertFalse(InvoiceStatus::Approved->canTransitionTo(InvoiceStatus::PartiallyPaid));
        $this->assertFalse(InvoiceStatus::PartiallyPaid->canTransitionTo(InvoiceStatus::Paid));
    }

    public function test_payment_tracked_statuses_are_the_live_billable_ones(): void
    {
        $this->assertTrue(InvoiceStatus::Approved->isPaymentTracked());
        $this->assertTrue(InvoiceStatus::Sent->isPaymentTracked());
        $this->assertTrue(InvoiceStatus::PartiallyPaid->isPaymentTracked());
        $this->assertTrue(InvoiceStatus::Paid->isPaymentTracked());

        $this->assertFalse(InvoiceStatus::Draft->isPaymentTracked());
        $this->assertFalse(InvoiceStatus::Void->isPaymentTracked());
    }

    public function test_any_live_status_can_be_voided(): void
    {
        foreach ([InvoiceStatus::Draft, InvoiceStatus::Approved, InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid] as $status) {
            $this->assertTrue($status->canTransitionTo(InvoiceStatus::Void), $status->value.' should be voidable');
        }
    }

    public function test_only_a_draft_is_editable(): void
    {
        $this->assertTrue(InvoiceStatus::Draft->isEditable());

        foreach ([InvoiceStatus::Approved, InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Void] as $status) {
            $this->assertFalse($status->isEditable(), $status->value.' must be immutable');
        }
    }

    public function test_issued_statuses_are_the_ones_the_client_has_seen(): void
    {
        $this->assertTrue(InvoiceStatus::Sent->isIssued());
        $this->assertTrue(InvoiceStatus::PartiallyPaid->isIssued());
        $this->assertTrue(InvoiceStatus::Paid->isIssued());
        $this->assertFalse(InvoiceStatus::Draft->isIssued());
        $this->assertFalse(InvoiceStatus::Approved->isIssued());
    }

    public function test_hosting_lines_are_not_operator_editable(): void
    {
        $this->assertFalse(InvoiceLineType::Hosting->isOperatorEditable());
        $this->assertFalse(InvoiceLineType::Recurring->isOperatorEditable());

        foreach (InvoiceLineType::operatorEditable() as $type) {
            $this->assertTrue($type->isOperatorEditable());
        }
    }

    public function test_sub_items_are_display_only_and_excluded_from_totals(): void
    {
        $invoice = Invoice::factory()->create();
        $parent = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount(100)->create();
        $child = InvoiceLine::factory()->subItemOf($parent)->amount(60)->create();

        $this->assertTrue($parent->countsTowardTotal());
        $this->assertFalse($child->countsTowardTotal());
        $this->assertTrue($parent->children->contains($child));
        $this->assertTrue($child->parent->is($parent));
    }

    public function test_top_level_lines_exclude_sub_items(): void
    {
        $invoice = Invoice::factory()->create();
        $parent = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->create();
        InvoiceLine::factory()->subItemOf($parent)->create();

        $this->assertCount(2, $invoice->lines()->get());
        $this->assertCount(1, $invoice->topLevelLines()->get());
    }

    public function test_deleting_an_invoice_removes_its_lines_and_payments(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceLine::factory()->for($invoice)->create();
        Payment::factory()->for($invoice)->create();

        $invoice->delete();

        $this->assertSame(0, InvoiceLine::count());
        $this->assertSame(0, Payment::withTrashed()->count());
    }

    public function test_payment_totals_and_balance(): void
    {
        $invoice = Invoice::factory()->withTotal(100.00)->create();
        Payment::factory()->for($invoice)->amount(30.00)->create();
        Payment::factory()->for($invoice)->amount(20.00)->create();

        $this->assertSame('50.00', $invoice->amountPaid());
        $this->assertSame('50.00', $invoice->balanceDue());
        $this->assertFalse($invoice->isOverpaid());
    }

    public function test_a_voided_payment_stops_counting_but_is_retained(): void
    {
        $invoice = Invoice::factory()->withTotal(100.00)->create();
        $payment = Payment::factory()->for($invoice)->amount(40.00)->create();

        $payment->delete();

        $this->assertSame('0.00', $invoice->fresh()->amountPaid());
        $this->assertSame(1, Payment::withTrashed()->count());
        $this->assertTrue(Payment::withTrashed()->first()->isVoided());
    }

    public function test_overpayment_is_reported_for_carry_forward(): void
    {
        $invoice = Invoice::factory()->withTotal(100.00)->create();
        Payment::factory()->for($invoice)->amount(120.00)->create();

        $this->assertTrue($invoice->isOverpaid());
        $this->assertSame('20.00', $invoice->overpaymentAmount());
        $this->assertSame('0.00', Invoice::factory()->withTotal(100.00)->create()->overpaymentAmount());
    }

    public function test_recording_user_is_kept_but_survives_their_deletion(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->create(['recorded_by_user_id' => $user->id]);

        $this->assertTrue($payment->recordedBy->is($user));

        $user->delete();

        $this->assertNotNull($payment->fresh());
        $this->assertNull($payment->fresh()->recorded_by_user_id);
    }

    public function test_monthly_template_bills_every_period_in_its_window(): void
    {
        $template = RecurringLineTemplate::factory()->window('2026-01-01')->create();

        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')));
    }

    public function test_template_does_not_bill_before_it_starts_or_after_it_ends(): void
    {
        $template = RecurringLineTemplate::factory()->window('2026-05-01', '2026-07-31')->create();

        $this->assertFalse($template->billsInPeriod(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30')));
        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
        $this->assertFalse($template->billsInPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
    }

    public function test_quarterly_template_bills_on_its_anniversary_months(): void
    {
        $template = RecurringLineTemplate::factory()
            ->cadence(RecurringCadence::Quarterly)
            ->window('2026-03-01')
            ->create();

        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')));
        $this->assertFalse($template->billsInPeriod(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30')));
        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31')));
    }

    public function test_annual_template_bills_once_a_year(): void
    {
        $template = RecurringLineTemplate::factory()
            ->cadence(RecurringCadence::Annually)
            ->window('2026-02-01')
            ->create();

        $this->assertTrue($template->billsInPeriod(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28')));
        $this->assertFalse($template->billsInPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
        $this->assertTrue($template->billsInPeriod(Carbon::parse('2027-02-01'), Carbon::parse('2027-02-28')));
    }

    public function test_for_client_scope_finds_templates_attached_via_a_project(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $project = Project::factory()->for($client)->create();

        RecurringLineTemplate::factory()->for($client)->create(['label' => 'Client level']);
        RecurringLineTemplate::factory()->forProject($project)->create(['label' => 'Project level']);
        RecurringLineTemplate::factory()->for(Client::factory()->for($business))->create(['label' => 'Someone else']);

        $found = RecurringLineTemplate::query()->forClient($client->id)->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['Client level', 'Project level'], $found);
    }

    public function test_active_during_scope_filters_by_window(): void
    {
        $client = Client::factory()->create();
        RecurringLineTemplate::factory()->for($client)->window('2026-01-01', '2026-06-30')->create(['label' => 'Ended']);
        RecurringLineTemplate::factory()->for($client)->window('2026-01-01')->create(['label' => 'Open']);
        RecurringLineTemplate::factory()->for($client)->window('2026-12-01')->create(['label' => 'Future']);

        $found = RecurringLineTemplate::query()
            ->activeDuring(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
            ->pluck('label')->all();

        $this->assertSame(['Open'], $found);
    }

    public function test_open_scope_excludes_settled_invoices(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        Invoice::factory()->for($business)->for($client)->forPeriod('2026-06')->status(InvoiceStatus::Draft)->create();
        Invoice::factory()->for($business)->for($client)->forPeriod('2026-07')->status(InvoiceStatus::Paid)->create();
        Invoice::factory()->for($business)->for($client)->forPeriod('2026-08')->status(InvoiceStatus::Void)->create();

        $this->assertSame(1, Invoice::query()->open()->count());
    }

    public function test_payment_method_flags_where_webhooks_will_land(): void
    {
        $this->assertTrue(PaymentMethod::Stripe->isAutomated());
        $this->assertFalse(PaymentMethod::Cheque->isAutomated());
    }
}
