<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\GenerateInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceLineRequest;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CurrentBusiness;
use App\Services\Billing\FxRateService;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly InvoiceBuilder $builder,
        private readonly InvoiceApprover $approver,
        private readonly InvoicePdfRenderer $pdf,
        private readonly FxRateService $fxRates,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('admin-v2.billing.invoices.index', [
            'currentBusiness' => $this->currentBusiness->get(),
            'statuses' => InvoiceStatus::options(),
            'periods' => $this->periodChoices(),
            'defaultPeriod' => BillingPeriod::previous(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        if ($business === null) {
            return response()->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $query = Invoice::query()->forBusiness($business->id)->with(['client', 'payments']);

        if (($status = (string) $request->input('status', '')) !== '' && InvoiceStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if (BillingPeriod::isValid((string) $request->input('period', ''))) {
            $query->where('period', $request->input('period'));
        }

        $total = (clone $query)->count();

        $invoices = $query
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->skip($start)
            ->take($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $invoices->map(fn (Invoice $invoice): array => [
                'invoice_number' => e($invoice->invoice_number),
                'client' => e($invoice->client->name),
                'period' => e(BillingPeriod::label($invoice->period)),
                'status' => sprintf(
                    '<span class="badge bg-%s">%s</span>',
                    e($invoice->status->badgeColor()),
                    e($invoice->status->label()),
                ),
                'total' => '$'.number_format((float) $invoice->total, 2).' '.e($invoice->issue_currency),
                'balance' => '$'.number_format((float) $invoice->balanceDue(), 2),
                'actions' => view('admin-v2.billing.invoices.partials.actions', ['invoice' => $invoice])->render(),
            ]),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view('admin-v2.billing.invoices.show', [
            'invoice' => $invoice->load(['client', 'business', 'topLevelLines.children', 'payments.recordedBy']),
            'lineTypes' => collect(InvoiceLineType::operatorEditable())
                ->mapWithKeys(fn (InvoiceLineType $t): array => [$t->value => $t->label()])
                ->all(),
            'lineCurrencies' => $this->lineCurrenciesFor($invoice),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }

    /**
     * Build or refresh a draft for a client and period.
     */
    public function generate(GenerateInvoiceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var Client $client */
        $client = Client::query()->findOrFail($validated['client_id']);

        try {
            $invoice = $this->builder->build($client, $validated['period']);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Draft {$invoice->invoice_number} ready for review.",
            'redirect' => route('admin.billing.invoices.show', $invoice),
        ]);
    }

    public function approve(Invoice $invoice): JsonResponse
    {
        $this->authorize('approve', $invoice);

        return $this->attempt(fn (): string => tap(
            $this->approver->approve($invoice),
            fn (Invoice $approved) => $this->pdf->store($approved),
        )->invoice_number.' approved.');
    }

    public function markSent(Invoice $invoice): JsonResponse
    {
        $this->authorize('send', $invoice);

        return $this->attempt(fn (): string => $this->approver->markSent($invoice)->invoice_number.' marked as sent.');
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('void', $invoice);

        $reason = $request->string('reason')->trim()->value();

        return $this->attempt(fn (): string => $this->approver
            ->void($invoice, $reason !== '' ? $reason : null)
            ->invoice_number.' voided.');
    }

    /**
     * Rebuild a draft from current cost data, keeping manual lines.
     */
    public function regenerate(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        return $this->attempt(function () use ($invoice): string {
            $this->builder->build($invoice->client, $invoice->period);

            return 'Draft rebuilt from the latest cost data.';
        });
    }

    public function storeLine(StoreInvoiceLineRequest $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validated();

        $type = InvoiceLineType::from($validated['line_type']);
        $sourceAmount = (string) $validated['amount'];
        $sourceCurrency = mb_strtoupper($validated['currency'] ?? $invoice->issue_currency);

        // A one-off cost can be incurred in a currency the client is not billed
        // in — a domain renewal priced in USD on a CAD invoice. Convert at the
        // period's rate and keep what was actually charged alongside it, so the
        // billed figure can be explained rather than just asserted.
        try {
            $rate = $this->fxRates->rateFor($sourceCurrency, $invoice->issue_currency, $invoice->period);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $amount = number_format((float) $sourceAmount * $rate, 2, '.', '');

        // Reductions are stored negative regardless of how they were typed, so
        // the total is a plain sum of the lines.
        if ($type->isNegative()) {
            $amount = '-'.ltrim($amount, '-');
        }

        $converted = $sourceCurrency !== $invoice->issue_currency;

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
            'line_type' => $type,
            'amount' => $amount,
            'source_amount' => $converted ? $sourceAmount : null,
            'source_currency' => $converted ? $sourceCurrency : null,
            'fx_rate_applied' => $converted ? $rate : null,
            'display_order' => (int) $invoice->lines()->max('display_order') + 1,
        ]);

        $this->builder->recalculateTotals($invoice->fresh());

        return response()->json([
            'success' => true,
            'message' => $converted
                ? sprintf('Line added — %s %s converted to %s %s.', $sourceCurrency, $sourceAmount, $invoice->issue_currency, ltrim($amount, '-'))
                : 'Line added.',
        ]);
    }

    public function destroyLine(Invoice $invoice, InvoiceLine $line): JsonResponse
    {
        $this->authorize('update', $invoice);

        if ($line->invoice_id !== $invoice->id) {
            return response()->json(['success' => false, 'message' => 'That line belongs to another invoice.'], 404);
        }

        if (! $line->line_type->isOperatorEditable()) {
            return response()->json([
                'success' => false,
                'message' => 'Derived lines cannot be removed. Add an adjustment line instead so the trail is preserved.',
            ], 422);
        }

        $line->delete();
        $this->builder->recalculateTotals($invoice->fresh());

        return response()->json(['success' => true, 'message' => 'Line removed.']);
    }

    public function downloadPdf(Invoice $invoice): StreamedResponse
    {
        $this->authorize('downloadPdf', $invoice);

        $contents = $this->pdf->contents($invoice);

        return response()->streamDownload(
            fn () => print ($contents),
            $this->pdf->downloadFilename($invoice),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Preview the rendered invoice exactly as the client will see it.
     */
    public function preview(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view($invoice->template_view_snapshot, ['invoice' => $invoice]);
    }

    /**
     * Clients of the current business, for the generate form.
     */
    public function availableClients(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $business = $this->currentBusiness->get();

        return response()->json([
            'success' => true,
            'clients' => $business === null
                ? []
                : Client::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Run an action that may refuse, turning a domain refusal into a 422 the
     * UI can surface rather than a 500.
     *
     * @param  callable(): string  $action
     */
    private function attempt(callable $action): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'message' => $action()]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Currencies a manual line may be entered in: the invoice's own, plus
     * anything the business supports.
     *
     * @return array<string, string>
     */
    private function lineCurrenciesFor(Invoice $invoice): array
    {
        return collect([$invoice->issue_currency, $invoice->business->default_currency])
            ->merge($invoice->business->supported_currencies ?? [])
            ->filter()
            ->map(fn (string $c): string => mb_strtoupper($c))
            ->unique()
            ->mapWithKeys(fn (string $c): array => [$c => $c])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function periodChoices(): array
    {
        $choices = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $period = $cursor->copy()->subMonths($i)->format('Y-m');
            $choices[$period] = BillingPeriod::label($period);
        }

        return $choices;
    }
}
