<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentRecorder $recorder) {}

    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validated();

        try {
            $this->recorder->record(
                invoice: $invoice,
                amount: (string) $validated['amount'],
                method: PaymentMethod::from($validated['method']),
                receivedAt: Carbon::parse($validated['received_at']),
                reference: $validated['reference'] ?? null,
                notes: $validated['notes'] ?? null,
                recordedBy: $request->user(),
            );
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $fresh = $invoice->fresh();

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded. Invoice is now '.$fresh->status->label().'.',
            'status' => $fresh->status->value,
            'balance' => $fresh->balanceDue(),
        ]);
    }

    public function destroy(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);

        if ($payment->invoice_id !== $invoice->id) {
            return response()->json(['success' => false, 'message' => 'That payment belongs to another invoice.'], 404);
        }

        try {
            $this->recorder->void(
                payment: $payment,
                reason: $request->string('reason')->trim()->value() ?: null,
                voidedBy: $request->user(),
            );
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $fresh = $invoice->fresh();

        return response()->json([
            'success' => true,
            'message' => 'Payment voided. Invoice is now '.$fresh->status->label().'.',
        ]);
    }
}
