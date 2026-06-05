<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceBuilder $builder,
        private readonly InvoiceApprover $approver,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('admin-v2.billing.invoices.index');
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $currentBusiness = session('billing_current_business_id')
            ? Business::find(session('billing_current_business_id'))
            : null;

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = (string) $request->input('search.value', '');
        $statusFilter = (string) $request->input('status', '');

        $query = Invoice::query()
            ->with(['client', 'business'])
            ->when($currentBusiness, fn ($q) => $q->where('business_id', $currentBusiness->id))
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->when($searchValue !== '', function ($q) use ($searchValue): void {
                $q->where(function ($inner) use ($searchValue): void {
                    $inner->where('invoice_number', 'like', "%{$searchValue}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$searchValue}%"));
                });
            });

        $total = Invoice::query()
            ->when($currentBusiness, fn ($q) => $q->where('business_id', $currentBusiness->id))
            ->count();
        $filtered = $query->count();

        $invoices = $query->orderByDesc('created_at')->skip($start)->take($length)->get();

        $rows = $invoices->map(fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'invoice_number' => e($invoice->invoice_number),
            'client' => e($invoice->client->name),
            'period' => $invoice->period_start->format('M Y'),
            'total' => $invoice->issue_currency.' '.number_format($invoice->total, 2),
            'status' => '<span class="badge bg-'.$invoice->status->badgeColor().'">'.$invoice->status->label().'</span>',
            'created_at' => $invoice->created_at?->format('M j, Y'),
            'actions' => view('admin-v2.billing.invoices.partials.actions', ['invoice' => $invoice])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['client.business', 'lines.project', 'payments']);

        return view('admin-v2.billing.invoices.show', [
            'invoice' => $invoice,
            'lineTypes' => InvoiceLineType::cases(),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }

    public function generate(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $client = Client::with('business')->findOrFail($request->integer('client_id'));

        try {
            $invoice = $this->builder->build($client->business, $client, $request->string('period')->value());
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['period' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'invoice_id' => $invoice->id]);
        }

        return redirect()->route('admin.billing.invoices.show', $invoice)->with('status', 'Invoice draft generated.');
    }

    public function approve(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        try {
            $this->approver->approve($invoice);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Invoice approved.']);
    }

    public function send(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        try {
            $this->approver->send($invoice);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Invoice sent to client.']);
    }

    public function void(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        try {
            $this->approver->void($invoice);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Invoice voided.']);
    }

    public function download(Invoice $invoice): mixed
    {
        $this->authorize('view', $invoice);

        if ($invoice->pdf_path === null || ! \Illuminate\Support\Facades\Storage::exists($invoice->pdf_path)) {
            abort(404, 'Invoice file not found.');
        }

        return \Illuminate\Support\Facades\Storage::download($invoice->pdf_path, $invoice->invoice_number.'.html');
    }
}
