<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Exceptions\Billing\InvalidInvoiceTransition;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records, edits and voids payments, and keeps the invoice's status in step.
 *
 * Status is always *derived* from the live payment total rather than set by
 * hand, so the two can never disagree. Every write is audited: money moving is
 * the part of this system a client is most likely to dispute.
 */
final class PaymentRecorder
{
    public function __construct(
        private readonly InvoiceApprover $approver,
        private readonly AuditLogger $audit,
    ) {}

    public function record(
        Invoice $invoice,
        string $amount,
        PaymentMethod $method,
        ?Carbon $receivedAt = null,
        ?string $reference = null,
        ?string $notes = null,
        ?User $recordedBy = null,
    ): Payment {
        $this->assertAcceptsPayments($invoice);
        $this->assertPositive($amount);

        return DB::transaction(function () use ($invoice, $amount, $method, $receivedAt, $reference, $notes, $recordedBy): Payment {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'received_at' => $receivedAt ?? now(),
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'recorded_by_user_id' => $recordedBy?->id,
            ]);

            $this->audit->logCritical('payment_recorded', $payment, null, [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $payment->amount,
                'method' => $method->value,
                'reference' => $reference,
            ], ['billing', 'invoice', 'payment']);

            $this->syncStatus($invoice);

            return $payment;
        });
    }

    /**
     * Correct a recorded payment — a mistyped amount, the wrong date, a
     * reference that turned out to belong to a different transfer.
     *
     * @param  array{amount?: string, received_at?: Carbon, method?: PaymentMethod, reference?: string|null, notes?: string|null}  $changes
     */
    public function update(Payment $payment, array $changes, ?User $updatedBy = null): Payment
    {
        if (array_key_exists('amount', $changes)) {
            $this->assertPositive($changes['amount']);
        }

        $invoice = $payment->invoice;
        $this->assertAcceptsPayments($invoice);

        return DB::transaction(function () use ($payment, $changes, $invoice, $updatedBy): Payment {
            $original = $payment->only(['amount', 'received_at', 'method', 'reference', 'notes']);

            $payment->fill($changes)->save();

            $this->audit->logCritical('payment_updated', $payment, $original, [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $payment->amount,
                'updated_by' => $updatedBy?->id,
            ], ['billing', 'invoice', 'payment']);

            $this->syncStatus($invoice);

            return $payment;
        });
    }

    /**
     * Reverse a payment. Soft-deleted rather than removed, so a payment that
     * was recorded and later reversed stays visible in the audit trail.
     */
    public function void(Payment $payment, ?string $reason = null, ?User $voidedBy = null): Payment
    {
        $invoice = $payment->invoice;

        return DB::transaction(function () use ($payment, $invoice, $reason, $voidedBy): Payment {
            $payment->delete();

            $this->audit->logCritical('payment_voided', $payment, null, [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $payment->amount,
                'reason' => $reason,
                'voided_by' => $voidedBy?->id,
            ], ['billing', 'invoice', 'payment']);

            $this->syncStatus($invoice);

            return $payment;
        });
    }

    /**
     * Recompute the invoice's status from its live payments and apply it.
     *
     * A void invoice is left alone — voiding is final, and a stray payment
     * against one is a bookkeeping problem, not a reason to revive it.
     */
    public function syncStatus(Invoice $invoice): InvoiceStatus
    {
        $invoice->refresh();

        if (! $invoice->status->isPaymentTracked()) {
            return $invoice->status;
        }

        $target = $this->deriveStatus($invoice);
        $this->approver->applyPaymentStatus($invoice, $target);

        return $target;
    }

    /**
     * paid when payments cover the total, partially_paid while some of it is
     * covered, and otherwise back to whether the client has been sent it.
     */
    private function deriveStatus(Invoice $invoice): InvoiceStatus
    {
        $paid = $invoice->amountPaid();

        if (bccomp($paid, $invoice->total, 2) >= 0 && bccomp($invoice->total, '0.00', 2) === 1) {
            return InvoiceStatus::Paid;
        }

        if (bccomp($paid, '0.00', 2) === 1) {
            return InvoiceStatus::PartiallyPaid;
        }

        return $invoice->sent_at !== null ? InvoiceStatus::Sent : InvoiceStatus::Approved;
    }

    private function assertAcceptsPayments(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            throw InvalidInvoiceTransition::because($invoice, 'is still a draft and cannot take payments');
        }

        if ($invoice->status === InvoiceStatus::Void) {
            throw InvalidInvoiceTransition::because($invoice, 'is void and cannot take payments');
        }
    }

    private function assertPositive(string $amount): void
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Payment amount [{$amount}] is not a number.");
        }

        if (bccomp($amount, '0.00', 2) !== 1) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }
    }
}
