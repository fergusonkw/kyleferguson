<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Exceptions\Billing\InvalidInvoiceTransition;
use App\Models\Billing\Invoice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Owns every invoice status change.
 *
 * No invoice leaves draft without an explicit approval, and nothing returns to
 * draft once it has. Every transition is written to the audit log, because a
 * status is a claim about what a client was told and when.
 */
final class InvoiceApprover
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Draft to approved. This is the moment the operator takes responsibility
     * for the numbers, so the invoice must actually have some.
     */
    public function approve(Invoice $invoice): Invoice
    {
        $this->assertCanTransitionTo($invoice, InvoiceStatus::Approved);

        if ($invoice->lines()->where('is_display_only', false)->doesntExist()) {
            throw InvalidInvoiceTransition::because($invoice, 'has no billable lines to approve');
        }

        if (bccomp($invoice->total, '0.00', 2) === -1) {
            throw InvalidInvoiceTransition::because($invoice, 'has a negative total and cannot be approved');
        }

        return $this->apply($invoice, InvoiceStatus::Approved, function (Invoice $invoice): void {
            $issuedOn = now();
            $invoice->forceFill([
                'status' => InvoiceStatus::Approved,
                'approved_at' => $issuedOn,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $issuedOn->copy()->addDays($invoice->business->payment_terms_days)->toDateString(),
            ])->save();
        });
    }

    /**
     * Approved to sent. Called once the client mail has actually gone out, so
     * `sent_at` means "the client has it", not "we intended to send it".
     */
    public function markSent(Invoice $invoice): Invoice
    {
        $this->assertCanTransitionTo($invoice, InvoiceStatus::Sent);

        return $this->apply($invoice, InvoiceStatus::Sent, function (Invoice $invoice): void {
            $invoice->forceFill([
                'status' => InvoiceStatus::Sent,
                'sent_at' => now(),
            ])->save();
        });
    }

    /**
     * Void an invoice at any live status. Voided invoices are never deleted or
     * re-issued — the number stays consumed so the sequence has no silent gaps.
     */
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        $this->assertCanTransitionTo($invoice, InvoiceStatus::Void);

        return $this->apply($invoice, InvoiceStatus::Void, function (Invoice $invoice) use ($reason): void {
            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'notes' => $reason !== null
                    ? trim($invoice->notes."\nVoided: ".$reason)
                    : $invoice->notes,
            ])->save();
        }, ['reason' => $reason]);
    }

    /**
     * Move to a payment-derived status. Only {@see PaymentRecorder} should call
     * this — an operator cannot mark an invoice paid without a payment record.
     */
    public function applyPaymentStatus(Invoice $invoice, InvoiceStatus $status): Invoice
    {
        if ($invoice->status === $status) {
            return $invoice;
        }

        $this->assertCanTransitionTo($invoice, $status);

        return $this->apply($invoice, $status, function (Invoice $invoice) use ($status): void {
            $invoice->forceFill(['status' => $status])->save();
        });
    }

    public function assertCanTransitionTo(Invoice $invoice, InvoiceStatus $to): void
    {
        if (! $invoice->status->canTransitionTo($to)) {
            throw InvalidInvoiceTransition::between($invoice, $to);
        }
    }

    /**
     * @param  callable(Invoice): void  $mutate
     * @param  array<string, mixed>  $context
     */
    private function apply(Invoice $invoice, InvoiceStatus $to, callable $mutate, array $context = []): Invoice
    {
        $from = $invoice->status;

        return DB::transaction(function () use ($invoice, $from, $to, $mutate, $context): Invoice {
            $mutate($invoice);

            $this->audit->logCritical(
                'invoice_status_changed',
                $invoice,
                null,
                array_merge([
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'business_id' => $invoice->business_id,
                    'client_id' => $invoice->client_id,
                    'from' => $from->value,
                    'to' => $to->value,
                    'total' => $invoice->total,
                ], $context),
                ['billing', 'invoice', 'status'],
            );

            return $invoice;
        });
    }
}
