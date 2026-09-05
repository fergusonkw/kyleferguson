<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\ReceivablesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Visibility onto money owed.
 *
 * Every figure here was already derivable — invoice totals and payment records
 * have existed since Phase 3. What did not exist was anywhere to read them: a
 * payment could only be seen inside the one invoice it belonged to, and
 * `due_on` was written at approval and never read by anything afterwards.
 */
final class CheckpointF3ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private int $periodCursor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-04 09:00:00');

        $this->business = Business::factory()->create([
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD', 'USD'],
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'billing_currency' => 'CAD',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_requires_permission(): void
    {
        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.receivables.index'))
            ->assertForbidden();
    }

    public function test_the_page_loads_for_admin(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.receivables.index'))
            ->assertOk()
            ->assertSee('Receivables');
    }

    public function test_only_issued_unpaid_invoices_count_as_owed(): void
    {
        // A draft has not been agreed, an approved one has not been given to
        // the client, and a void one is not money at all.
        $this->invoice(InvoiceStatus::Draft, '100.00');
        $this->invoice(InvoiceStatus::Approved, '200.00');
        $this->invoice(InvoiceStatus::Void, '400.00');
        $sent = $this->invoice(InvoiceStatus::Sent, '800.00');

        $summary = $this->summarize();

        $this->assertSame(1, $summary->outstandingCount);
        $this->assertSame('800.00', $summary->outstanding->get('CAD'));
        $this->assertSame($sent->id, $this->reporter()->outstandingInvoices($this->business->id)->first()->id);
    }

    public function test_an_approved_invoice_is_reported_separately_from_what_is_owed(): void
    {
        // Approved and never sent is the failure only the operator can see.
        $this->invoice(InvoiceStatus::Approved, '250.00');

        $summary = $this->summarize();

        $this->assertSame(0, $summary->outstandingCount);
        $this->assertSame(1, $summary->awaitingSendCount);
        $this->assertSame('250.00', $summary->awaitingSend->get('CAD'));
        $this->assertTrue($summary->needsAttention());
    }

    public function test_payments_reduce_the_balance_and_a_settled_invoice_drops_out(): void
    {
        $partly = $this->invoice(InvoiceStatus::Sent, '500.00');
        Payment::factory()->for($partly)->amount(200)->create();

        $settled = $this->invoice(InvoiceStatus::Sent, '300.00');
        Payment::factory()->for($settled)->amount(300)->create();

        $summary = $this->summarize();

        $this->assertSame(1, $summary->outstandingCount);
        $this->assertSame('300.00', $summary->outstanding->get('CAD'));
    }

    public function test_a_voided_payment_puts_the_balance_back(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, '400.00');
        $payment = Payment::factory()->for($invoice)->amount(400)->create();

        $this->assertTrue($this->summarize()->outstanding->isEmpty());

        $payment->delete();

        $this->assertSame('400.00', $this->summarize()->outstanding->get('CAD'));
    }

    public function test_overdue_is_measured_from_the_due_date(): void
    {
        $this->invoice(InvoiceStatus::Sent, '100.00', dueOn: '2026-09-30');
        $late = $this->invoice(InvoiceStatus::Sent, '250.00', dueOn: '2026-08-20');

        $summary = $this->summarize();

        $this->assertSame(2, $summary->outstandingCount);
        $this->assertSame(1, $summary->overdueCount);
        $this->assertSame('250.00', $summary->overdue->get('CAD'));
        $this->assertSame(15, $summary->oldestOverdueDays);
        $this->assertSame(15, $this->reporter()->daysOverdue($late));
    }

    public function test_an_invoice_due_today_is_not_yet_late(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, '100.00', dueOn: '2026-09-04');

        $this->assertNull($this->reporter()->daysOverdue($invoice));
        $this->assertSame(0, $this->summarize()->overdueCount);
    }

    public function test_an_issued_invoice_without_a_due_date_is_flagged_not_assumed_current(): void
    {
        // Not knowing whether something is late is a different situation from
        // knowing it is not, so it gets its own count and its own bucket.
        $this->invoice(InvoiceStatus::Sent, '100.00', dueOn: null);

        $summary = $this->summarize();

        $this->assertSame(1, $summary->missingDueDateCount);
        $this->assertSame(0, $summary->overdueCount);
        $this->assertTrue($summary->needsAttention());

        $bucket = collect($this->reporter()->aging($this->business->id))->firstWhere('key', 'no_due_date');
        $this->assertSame(1, $bucket['count']);
    }

    public function test_balances_are_bucketed_by_how_late_they_are(): void
    {
        $this->invoice(InvoiceStatus::Sent, '10.00', dueOn: '2026-09-30');
        $this->invoice(InvoiceStatus::Sent, '20.00', dueOn: '2026-08-25');
        $this->invoice(InvoiceStatus::Sent, '30.00', dueOn: '2026-07-20');
        $this->invoice(InvoiceStatus::Sent, '40.00', dueOn: '2026-06-20');
        $this->invoice(InvoiceStatus::Sent, '50.00', dueOn: '2026-01-01');

        $buckets = collect($this->reporter()->aging($this->business->id))->keyBy('key');

        $this->assertSame('10.00', $buckets['not_due']['totals']->get('CAD'));
        $this->assertSame('20.00', $buckets['1_30']['totals']->get('CAD'));
        $this->assertSame('30.00', $buckets['31_60']['totals']->get('CAD'));
        $this->assertSame('40.00', $buckets['61_90']['totals']->get('CAD'));
        $this->assertSame('50.00', $buckets['90_plus']['totals']->get('CAD'));
    }

    public function test_currencies_are_totalled_apart_never_added_together(): void
    {
        // Adding CAD to USD would produce a number that looks authoritative
        // and means nothing.
        $usdClient = Client::factory()->for($this->business)->create(['billing_currency' => 'USD']);

        $this->invoice(InvoiceStatus::Sent, '100.00');
        Invoice::factory()->for($this->business)->for($usdClient)->sent()
            ->withTotal(75.00)->create(['issue_currency' => 'USD', 'due_on' => '2026-09-30']);

        $summary = $this->summarize();

        $this->assertSame('100.00', $summary->outstanding->get('CAD'));
        $this->assertSame('75.00', $summary->outstanding->get('USD'));
        $this->assertSame('$100.00 CAD', $summary->outstanding->headline('CAD'));
        $this->assertSame('plus $75.00 USD', $summary->outstanding->remainderLabel('CAD'));
    }

    public function test_the_headline_reads_zero_rather_than_borrowing_another_currency(): void
    {
        $usdClient = Client::factory()->for($this->business)->create(['billing_currency' => 'USD']);
        Invoice::factory()->for($this->business)->for($usdClient)->sent()
            ->withTotal(75.00)->create(['issue_currency' => 'USD', 'due_on' => '2026-09-30']);

        $summary = $this->summarize();

        $this->assertSame('$0.00 CAD', $summary->outstanding->headline('CAD'));
        $this->assertSame('plus $75.00 USD', $summary->outstanding->remainderLabel('CAD'));
    }

    public function test_collected_covers_the_last_thirty_days_only(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, '900.00');

        Payment::factory()->for($invoice)->amount(100)->create(['received_at' => '2026-09-01']);
        Payment::factory()->for($invoice)->amount(50)->create(['received_at' => '2026-06-01']);

        $this->assertSame('100.00', $this->summarize()->collectedRecently->get('CAD'));
    }

    public function test_the_payment_ledger_spans_every_invoice(): void
    {
        $first = $this->invoice(InvoiceStatus::Sent, '100.00');
        $second = $this->invoice(InvoiceStatus::Sent, '200.00');

        Payment::factory()->for($first)->amount(10)->create(['received_at' => '2026-09-01']);
        Payment::factory()->for($second)->amount(20)->create(['received_at' => '2026-09-02']);

        $payments = $this->reporter()->recentPayments($this->business->id);

        $this->assertCount(2, $payments);
        $this->assertSame($second->id, $payments->first()->invoice_id);
    }

    public function test_another_businesss_invoices_are_not_counted(): void
    {
        $other = Business::factory()->create();
        $otherClient = Client::factory()->for($other)->create();
        Invoice::factory()->for($other)->for($otherClient)->sent()
            ->withTotal(999.00)->create(['due_on' => '2026-01-01']);

        $summary = $this->summarize();

        $this->assertSame(0, $summary->outstandingCount);
        $this->assertTrue($summary->outstanding->isEmpty());
    }

    public function test_the_page_lists_an_overdue_invoice_with_its_age(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Sent, '250.00', dueOn: '2026-08-20');

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.receivables.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('Acme Industries')
            ->assertSee('15 days late');
    }

    public function test_the_billing_dashboard_shows_the_same_figures(): void
    {
        $this->invoice(InvoiceStatus::Sent, '250.00', dueOn: '2026-08-20');

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Outstanding')
            ->assertSee('$250.00 CAD')
            ->assertSee('past due');
    }

    public function test_the_main_dashboard_shows_receivables_to_a_billing_admin(): void
    {
        $this->invoice(InvoiceStatus::Sent, '250.00', dueOn: '2026-08-20');

        $this->actingAs($this->createAdmin())
            ->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Money owed')
            ->assertSee('$250.00 CAD');
    }

    public function test_the_main_dashboard_hides_money_from_a_user_without_billing_access(): void
    {
        $this->invoice(InvoiceStatus::Sent, '250.00', dueOn: '2026-08-20');

        $this->actingAs($this->userWithPermissions([Permission::ViewAuditLogs]))
            ->get(route('admin.home'))
            ->assertOk()
            ->assertDontSee('Money owed')
            ->assertDontSee('$250.00 CAD');
    }

    private function summarize(): \App\Services\Billing\Dto\ReceivablesSummary
    {
        return $this->reporter()->summarize($this->business->fresh());
    }

    private function reporter(): ReceivablesReporter
    {
        return app(ReceivablesReporter::class);
    }

    /**
     * A client may only have one invoice per period, so each one gets its own.
     * The period is irrelevant to receivables — `due_on` is what decides
     * whether money is late — so stepping through months keeps the constraint
     * satisfied without affecting anything under test.
     */
    private function invoice(InvoiceStatus $status, string $total, ?string $dueOn = '2026-09-30'): Invoice
    {
        $this->periodCursor++;

        return Invoice::factory()
            ->for($this->business)
            ->for($this->client)
            ->forPeriod(sprintf('2025-%02d', $this->periodCursor))
            ->status($status)
            ->withTotal((float) $total)
            ->create(['due_on' => $dueOn, 'issue_currency' => 'CAD']);
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
}
