<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentRecorder $recorder) {}

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'received_at' => ['required', 'date'],
            'method' => ['required', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, PaymentMethod::cases()))],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->recorder->record(
                $invoice,
                (float) $validated['amount'],
                PaymentMethod::from($validated['method']),
                $validated['received_at'],
                $validated['reference'] ?? null,
                $validated['notes'] ?? null,
                $request->user()?->id,
            );
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded.',
            'new_status' => $invoice->fresh()->status->label(),
        ]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->authorize('update', $payment->invoice);

        try {
            $this->recorder->void($payment);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Payment voided.']);
    }
}
