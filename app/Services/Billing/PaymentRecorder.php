<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PaymentRecorder
{
    public function record(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        string $receivedAt,
        ?string $reference = null,
        ?string $notes = null,
        ?int $recordedByUserId = null,
    ): Payment {
        if ($invoice->status === InvoiceStatus::Void) {
            throw new RuntimeException('Cannot record payment on a voided invoice.');
        }

        return DB::transaction(function () use (
            $invoice, $amount, $method, $receivedAt, $reference, $notes, $recordedByUserId
        ): Payment {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'received_at' => $receivedAt,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'recorded_by_user_id' => $recordedByUserId,
            ]);

            $this->recomputeStatus($invoice);

            return $payment;
        });
    }

    public function void(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment->delete();
            $this->recomputeStatus($payment->invoice);
        });
    }

    private function recomputeStatus(Invoice $invoice): void
    {
        $totalPaid = (float) Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('deleted_at')
            ->sum('amount');

        $status = match (true) {
            $totalPaid <= 0 => InvoiceStatus::Sent,
            $totalPaid < $invoice->total => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Paid,
        };

        $invoice->update(['status' => $status]);
    }
}
