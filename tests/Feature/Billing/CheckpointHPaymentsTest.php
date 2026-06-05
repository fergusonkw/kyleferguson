<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CheckpointHPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->for($this->business)->create();

        $this->invoice = Invoice::factory()->sent()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'total' => 200.0,
        ]);
    }

    public function test_full_payment_marks_invoice_paid(): void
    {
        app(PaymentRecorder::class)->record(
            $this->invoice, 200.0, PaymentMethod::Etransfer, now()->toDateTimeString()
        );

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
    }

    public function test_partial_payment_marks_invoice_partially_paid(): void
    {
        app(PaymentRecorder::class)->record(
            $this->invoice, 100.0, PaymentMethod::Etransfer, now()->toDateTimeString()
        );

        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->fresh()->status);
    }

    public function test_partial_then_full_payment_progression(): void
    {
        $recorder = app(PaymentRecorder::class);

        $recorder->record($this->invoice, 50.0, PaymentMethod::Cheque, now()->toDateTimeString());
        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->fresh()->status);

        $recorder->record($this->invoice, 100.0, PaymentMethod::Cheque, now()->toDateTimeString());
        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->fresh()->status);

        $recorder->record($this->invoice, 50.0, PaymentMethod::Cheque, now()->toDateTimeString());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
    }

    public function test_void_payment_reverts_status(): void
    {
        $recorder = app(PaymentRecorder::class);

        $payment = $recorder->record(
            $this->invoice, 200.0, PaymentMethod::Etransfer, now()->toDateTimeString()
        );

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);

        $recorder->void($payment);

        $this->assertSame(InvoiceStatus::Sent, $this->invoice->fresh()->status);
        $this->assertSoftDeleted($payment);
    }

    public function test_payment_on_voided_invoice_throws(): void
    {
        $this->invoice->update(['status' => InvoiceStatus::Void]);

        $this->expectException(RuntimeException::class);

        app(PaymentRecorder::class)->record(
            $this->invoice, 200.0, PaymentMethod::Etransfer, now()->toDateTimeString()
        );
    }

    public function test_overpayment_marks_invoice_paid(): void
    {
        app(PaymentRecorder::class)->record(
            $this->invoice, 250.0, PaymentMethod::Etransfer, now()->toDateTimeString()
        );

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
    }

    public function test_payment_stores_method_and_reference(): void
    {
        app(PaymentRecorder::class)->record(
            $this->invoice, 200.0, PaymentMethod::Cheque, now()->toDateTimeString(),
            'CHQ-1234', 'Test notes'
        );

        $payment = Payment::first();
        $this->assertSame(PaymentMethod::Cheque, $payment->method);
        $this->assertSame('CHQ-1234', $payment->reference);
        $this->assertSame('Test notes', $payment->notes);
    }

    public function test_total_paid_excludes_voided_payments(): void
    {
        $recorder = app(PaymentRecorder::class);

        $p1 = $recorder->record($this->invoice, 100.0, PaymentMethod::Etransfer, now()->toDateTimeString());
        $recorder->record($this->invoice, 100.0, PaymentMethod::Etransfer, now()->toDateTimeString());
        $recorder->void($p1);

        // Only the second payment should count
        $this->assertEqualsWithDelta(100.0, $this->invoice->fresh()->totalPaid(), 0.01);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->fresh()->status);
    }
}
