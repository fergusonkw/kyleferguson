<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Exceptions\Billing\InvalidInvoiceTransition;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Payment recording, and the invoice status it derives.
 *
 * Status is never set by hand here — it is always recomputed from the live
 * payment total, so the two cannot disagree.
 */
final class CheckpointE3PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->for($this->business)->create();
    }

    public function test_a_partial_payment_moves_the_invoice_to_partially_paid(): void
    {
        $invoice = $this->sentInvoice(100.00);

        $this->recorder()->record($invoice, '40.00', PaymentMethod::ETransfer);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('40.00', $invoice->fresh()->amountPaid());
        $this->assertSame('60.00', $invoice->fresh()->balanceDue());
    }

    public function test_partial_payments_accumulate_to_paid(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();

        $recorder->record($invoice, '40.00', PaymentMethod::ETransfer);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $recorder->record($invoice->fresh(), '35.00', PaymentMethod::Cheque);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $recorder->record($invoice->fresh(), '25.00', PaymentMethod::Cash);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->balanceDue());
    }

    public function test_paying_the_exact_total_marks_it_paid(): void
    {
        $invoice = $this->sentInvoice(100.00);

        $this->recorder()->record($invoice, '100.00', PaymentMethod::ETransfer);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_an_overpayment_still_marks_it_paid_and_is_reported(): void
    {
        $invoice = $this->sentInvoice(100.00);

        $this->recorder()->record($invoice, '130.00', PaymentMethod::ETransfer);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertTrue($fresh->isOverpaid());
        $this->assertSame('30.00', $fresh->overpaymentAmount());
    }

    public function test_a_payment_can_be_recorded_before_the_invoice_is_marked_sent(): void
    {
        $invoice = $this->approvedInvoice(100.00);

        $this->recorder()->record($invoice, '50.00', PaymentMethod::Cash);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    public function test_a_draft_cannot_take_payments(): void
    {
        $invoice = $this->invoice(100.00, InvoiceStatus::Draft);

        $this->expectException(InvalidInvoiceTransition::class);
        $this->expectExceptionMessage('still a draft');

        $this->recorder()->record($invoice, '10.00', PaymentMethod::Cash);
    }

    public function test_a_void_invoice_cannot_take_payments(): void
    {
        $invoice = $this->invoice(100.00, InvoiceStatus::Void);

        $this->expectException(InvalidInvoiceTransition::class);
        $this->expectExceptionMessage('is void');

        $this->recorder()->record($invoice, '10.00', PaymentMethod::Cash);
    }

    public function test_a_zero_or_negative_payment_is_rejected(): void
    {
        $invoice = $this->sentInvoice(100.00);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->recorder()->record($invoice, '0.00', PaymentMethod::Cash);
    }

    public function test_a_non_numeric_payment_is_rejected(): void
    {
        $invoice = $this->sentInvoice(100.00);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a number');

        $this->recorder()->record($invoice, 'forty dollars', PaymentMethod::Cash);
    }

    public function test_payment_details_are_stored(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $user = User::factory()->create();
        $received = Carbon::parse('2026-08-14 09:30:00');

        $payment = $this->recorder()->record(
            $invoice, '40.00', PaymentMethod::Interac,
            receivedAt: $received,
            reference: 'ETR-99120',
            notes: 'Paid from their ops account',
            recordedBy: $user,
        );

        $this->assertSame('40.00', $payment->amount);
        $this->assertSame(PaymentMethod::Interac, $payment->method);
        $this->assertSame('ETR-99120', $payment->reference);
        $this->assertSame('Paid from their ops account', $payment->notes);
        $this->assertTrue($received->equalTo($payment->received_at));
        $this->assertTrue($payment->recordedBy->is($user));
    }

    public function test_voiding_a_payment_walks_the_status_back(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();
        $payment = $recorder->record($invoice, '100.00', PaymentMethod::ETransfer);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $recorder->void($payment, 'Cheque bounced');

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->amountPaid());
    }

    public function test_voiding_one_of_several_payments_falls_back_to_partially_paid(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();
        $first = $recorder->record($invoice, '60.00', PaymentMethod::ETransfer);
        $recorder->record($invoice->fresh(), '40.00', PaymentMethod::Cheque);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $recorder->void($first);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('40.00', $invoice->fresh()->amountPaid());
    }

    public function test_voiding_every_payment_on_an_unsent_invoice_returns_it_to_approved(): void
    {
        $invoice = $this->approvedInvoice(100.00);
        $recorder = $this->recorder();
        $payment = $recorder->record($invoice, '50.00', PaymentMethod::Cash);

        $recorder->void($payment);

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
    }

    public function test_a_voided_payment_is_retained_for_the_audit_trail(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();
        $payment = $recorder->record($invoice, '50.00', PaymentMethod::Cash);

        $recorder->void($payment, 'Recorded against the wrong invoice');

        $this->assertSame(0, Payment::count());
        $this->assertSame(1, Payment::withTrashed()->count());
    }

    public function test_correcting_a_payment_amount_recomputes_the_status(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();
        $payment = $recorder->record($invoice, '100.00', PaymentMethod::ETransfer);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $recorder->update($payment, ['amount' => '60.00']);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('60.00', $invoice->fresh()->amountPaid());
    }

    public function test_correcting_a_payment_upward_can_settle_the_invoice(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();
        $payment = $recorder->record($invoice, '20.00', PaymentMethod::Cheque);

        $recorder->update($payment, ['amount' => '100.00', 'reference' => 'CHQ-4471']);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('CHQ-4471', $payment->fresh()->reference);
    }

    public function test_correcting_a_payment_to_a_negative_amount_is_rejected(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $payment = $this->recorder()->record($invoice, '20.00', PaymentMethod::Cheque);

        $this->expectException(InvalidArgumentException::class);

        $this->recorder()->update($payment, ['amount' => '-5.00']);
    }

    public function test_recording_editing_and_voiding_are_all_audited(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $recorder = $this->recorder();

        $payment = $recorder->record($invoice, '40.00', PaymentMethod::ETransfer, reference: 'ETR-1');
        $recorder->update($payment, ['amount' => '50.00']);
        $recorder->void($payment, 'Duplicate');

        $events = AuditLog::query()->whereIn('event', [
            'payment_recorded', 'payment_updated', 'payment_voided',
        ])->pluck('event')->all();

        $this->assertSame(['payment_recorded', 'payment_updated', 'payment_voided'], $events);

        $recorded = AuditLog::query()->where('event', 'payment_recorded')->firstOrFail();
        $this->assertSame('ETR-1', $recorded->new_values['reference']);
        $this->assertSame($invoice->invoice_number, $recorded->new_values['invoice_number']);
    }

    public function test_sync_status_leaves_a_void_invoice_alone(): void
    {
        $invoice = $this->sentInvoice(100.00);
        $this->recorder()->record($invoice, '100.00', PaymentMethod::Cash);
        $invoice->fresh()->forceFill(['status' => InvoiceStatus::Void])->save();

        $status = $this->recorder()->syncStatus($invoice->fresh());

        $this->assertSame(InvoiceStatus::Void, $status);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_a_zero_total_invoice_is_not_marked_paid_by_having_no_payments(): void
    {
        $invoice = $this->invoice(0.00, InvoiceStatus::Sent);

        $this->assertSame(InvoiceStatus::Sent, $this->recorder()->syncStatus($invoice));
    }

    private function recorder(): PaymentRecorder
    {
        return app(PaymentRecorder::class);
    }

    private function sentInvoice(float $total): Invoice
    {
        return $this->invoice($total, InvoiceStatus::Sent);
    }

    private function approvedInvoice(float $total): Invoice
    {
        return $this->invoice($total, InvoiceStatus::Approved);
    }

    private function invoice(float $total, InvoiceStatus $status): Invoice
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)
            ->withTotal($total)->status($status)->create();

        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount($total)->create();

        return $invoice->fresh();
    }
}
