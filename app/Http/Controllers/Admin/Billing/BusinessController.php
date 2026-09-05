<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBusinessRequest;
use App\Http\Requests\Billing\UpdateBusinessRequest;
use App\Models\Billing\Business;
use App\Services\AuditLogger;
use App\Services\Billing\BusinessLogoStore;
use App\Services\Billing\InvoiceTemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BusinessController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessLogoStore $logos,
        private readonly InvoiceTemplateRegistry $templates,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Business::class);

        return view('admin-v2.billing.businesses.index', [
            'invoiceTemplates' => $this->templates->invoiceTemplates(),
            'emailTemplates' => $this->templates->emailTemplates(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Business::class);

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = (string) $request->input('search.value', '');

        $query = Business::query()->withCount(['clients', 'costProviders']);

        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue): void {
                $q->where('name', 'like', "%{$searchValue}%")
                    ->orWhere('legal_name', 'like', "%{$searchValue}%")
                    ->orWhere('contact_email', 'like', "%{$searchValue}%");
            });
        }

        $total = Business::count();
        $filtered = $query->count();

        $businesses = $query->orderBy('name')->skip($start)->take($length)->get();

        $rows = $businesses->map(fn (Business $business): array => [
            'id' => $business->id,
            'name' => e($business->name),
            'contact_email' => e($business->contact_email),
            'default_currency' => e($business->default_currency),
            'clients_count' => $business->clients_count,
            'providers_count' => $business->cost_providers_count,
            'tax_registered' => $business->tax_registered_from
                ? '<span class="badge bg-success">Registered</span>'
                : '<span class="badge bg-default">Unregistered</span>',
            'actions' => view('admin-v2.billing.businesses.partials.actions', ['business' => $business])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $business = Business::create($request->validated());

        if ($request->hasFile('logo')) {
            $business->forceFill(['logo_path' => $this->logos->store($request->file('logo'))])->save();
        }

        $this->audit->logCreated($business, ['billing', 'business']);

        return response()->json([
            'success' => true,
            'message' => 'Business created successfully.',
            'data' => $business,
        ]);
    }

    public function edit(Business $business): JsonResponse
    {
        $this->authorize('view', $business);

        return response()->json([
            'success' => true,
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'legal_name' => $business->legal_name,
                'address' => $business->address,
                'contact_email' => $business->contact_email,
                'notification_email' => $business->notification_email,
                'brand_primary_color' => $business->brand_primary_color,
                'brand_secondary_color' => $business->brand_secondary_color,
                'invoice_number_prefix' => $business->invoice_number_prefix,
                'default_currency' => $business->default_currency,
                'supported_currencies' => $business->supported_currencies ?? ['CAD'],
                'fx_source' => $business->fx_source,
                'tax_registered_from' => $business->tax_registered_from?->format('Y-m-d'),
                'daily_reminder_time' => substr((string) $business->daily_reminder_time, 0, 5),
                'late_fee_terms' => $business->late_fee_terms,
                'payment_terms_days' => $business->payment_terms_days,
                'cheque_payable_to' => $business->cheque_payable_to,
                'invoice_template_view' => $business->invoice_template_view,
                'email_template_view' => $business->email_template_view,
                'has_logo' => filled($business->logo_path),
                'logo_preview' => $this->logos->dataUri($business->logo_path),
            ],
        ]);
    }

    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $original = $business->getOriginal();
        $business->update($request->validated());

        // Uploads never overwrite, so an invoice that snapshotted the old path
        // keeps rendering the logo it was issued with. The old file stays.
        if ($request->hasFile('logo')) {
            $business->forceFill(['logo_path' => $this->logos->store($request->file('logo'))])->save();
        } elseif ($request->boolean('remove_logo')) {
            $business->forceFill(['logo_path' => null])->save();
        }

        $this->audit->logUpdated($business, $original, ['billing', 'business']);

        return response()->json([
            'success' => true,
            'message' => 'Business updated successfully.',
            'data' => $business->fresh(),
        ]);
    }

    public function destroy(Business $business): JsonResponse
    {
        $this->authorize('delete', $business);

        if ($business->clients()->exists() || $business->costProviders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a business that still has clients or cost providers attached.',
            ], 422);
        }

        $this->audit->logDeleted($business, ['billing', 'business']);
        $business->delete();

        return response()->json([
            'success' => true,
            'message' => 'Business deleted successfully.',
        ]);
    }
}
