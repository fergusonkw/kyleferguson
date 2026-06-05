<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Http\Controllers\Controller;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceLineController extends Controller
{
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        if (! $invoice->isDraft()) {
            return response()->json(['success' => false, 'message' => 'Lines can only be added to draft invoices.'], 422);
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'line_type' => ['required', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, InvoiceLineType::cases()))],
            'amount' => ['required', 'numeric'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $maxOrder = $invoice->lines()->max('display_order') ?? -1;

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'label' => $validated['label'],
            'line_type' => $validated['line_type'],
            'amount' => $validated['amount'],
            'project_id' => $validated['project_id'] ?? null,
            'display_order' => $maxOrder + 1,
        ]);

        $this->recalculateTotals($invoice);

        return response()->json(['success' => true, 'line' => $line]);
    }

    public function update(Request $request, InvoiceLine $invoiceLine): JsonResponse
    {
        $this->authorize('update', $invoiceLine->invoice);

        if (! $invoiceLine->line_type->isEditable()) {
            return response()->json(['success' => false, 'message' => 'This line type cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
        ]);

        $invoiceLine->update($validated);
        $this->recalculateTotals($invoiceLine->invoice);

        return response()->json(['success' => true]);
    }

    public function destroy(InvoiceLine $invoiceLine): JsonResponse
    {
        $this->authorize('update', $invoiceLine->invoice);

        if (! $invoiceLine->line_type->isEditable()) {
            return response()->json(['success' => false, 'message' => 'This line type cannot be deleted.'], 422);
        }

        $invoice = $invoiceLine->invoice;
        $invoiceLine->delete();
        $this->recalculateTotals($invoice);

        return response()->json(['success' => true]);
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get();

        $subtotal = $lines->whereNotIn('line_type', [
            InvoiceLineType::Tax,
            InvoiceLineType::Discount,
            InvoiceLineType::Credit,
        ])->sum('amount');

        $tax = $lines->where('line_type', InvoiceLineType::Tax)->sum('amount');
        $discounts = $lines->whereIn('line_type', [
            InvoiceLineType::Discount,
            InvoiceLineType::Credit,
        ])->sum('amount');

        $total = round((float) $subtotal + (float) $tax - (float) $discounts, 2);

        $invoice->update([
            'subtotal' => round((float) $subtotal, 2),
            'total' => $total,
        ]);
    }
}
