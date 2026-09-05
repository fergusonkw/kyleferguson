<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\RecurringCadence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreRecurringLineTemplateRequest;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\AuditLogger;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Standing items that attach themselves to every draft — a Forge
 * subscription, a domain renewal, a retainer.
 */
final class RecurringLineTemplateController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', RecurringLineTemplate::class);

        $business = $this->currentBusiness->get();

        return view('admin-v2.billing.recurring-lines.index', [
            'currentBusiness' => $business,
            'cadenceOptions' => RecurringCadence::options(),
            'currencyOptions' => collect($business?->supported_currencies ?? ['CAD'])
                ->mapWithKeys(fn (string $c): array => [$c => $c])
                ->all(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RecurringLineTemplate::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        if ($business === null) {
            return response()->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $query = $this->scopedToBusiness($business->id)->with(['client', 'project.client']);

        $total = (clone $query)->count();
        $templates = $query->orderBy('label')->skip($start)->take($length)->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $templates->map(fn (RecurringLineTemplate $t): array => [
                'id' => $t->id,
                'label' => e($t->label),
                'applies_to' => $t->project !== null
                    ? e($t->project->client->name).' <span class="text-default-400">· '.e($t->project->name).'</span>'
                    : e($t->client?->name ?? '—'),
                'amount' => '$'.number_format((float) $t->amount, 2).' '.e($t->currency),
                'cadence' => e($t->cadence->label()),
                'window' => $this->presentWindow($t),
                'status' => $this->presentStatus($t),
                'actions' => view('admin-v2.billing.recurring-lines.partials.actions', ['template' => $t])->render(),
            ]),
        ]);
    }

    public function store(StoreRecurringLineTemplateRequest $request): JsonResponse
    {
        $template = RecurringLineTemplate::create($request->payload());
        $this->audit->logCreated($template, ['billing', 'recurring-line']);

        return response()->json([
            'success' => true,
            'message' => "\"{$template->label}\" will be added to drafts from "
                .BillingPeriod::label($template->active_from->format('Y-m')).'.',
        ]);
    }

    public function edit(RecurringLineTemplate $recurringLineTemplate): JsonResponse
    {
        $this->authorize('view', $recurringLineTemplate);

        return response()->json([
            'success' => true,
            'template' => [
                'id' => $recurringLineTemplate->id,
                'client_id' => $recurringLineTemplate->client_id,
                'project_id' => $recurringLineTemplate->project_id,
                'label' => $recurringLineTemplate->label,
                'amount' => $recurringLineTemplate->amount,
                'currency' => $recurringLineTemplate->currency,
                'cadence' => $recurringLineTemplate->cadence->value,
                'active_from' => $recurringLineTemplate->active_from->toDateString(),
                'active_to' => $recurringLineTemplate->active_to?->toDateString(),
                'notes' => $recurringLineTemplate->notes,
            ],
        ]);
    }

    public function update(
        StoreRecurringLineTemplateRequest $request,
        RecurringLineTemplate $recurringLineTemplate,
    ): JsonResponse {
        $original = $recurringLineTemplate->getRawOriginal();
        $recurringLineTemplate->update($request->payload());

        // Changing what a client is charged every period is worth a record.
        $this->audit->logCritical('recurring_line_changed', $recurringLineTemplate, $original,
            $recurringLineTemplate->getAttributes(), ['billing', 'recurring-line']);

        return response()->json([
            'success' => true,
            'message' => 'Recurring item updated. Rebuild any open draft to pick up the change.',
        ]);
    }

    public function destroy(RecurringLineTemplate $recurringLineTemplate): JsonResponse
    {
        $this->authorize('delete', $recurringLineTemplate);

        $this->audit->logDeleted($recurringLineTemplate, ['billing', 'recurring-line']);
        $recurringLineTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recurring item removed. Invoices already issued keep their copy of it.',
        ]);
    }

    /**
     * Clients and their projects for the attach-to selector.
     */
    public function targets(): JsonResponse
    {
        $this->authorize('viewAny', RecurringLineTemplate::class);

        $business = $this->currentBusiness->get();

        if ($business === null) {
            return response()->json(['success' => true, 'clients' => []]);
        }

        $clients = Client::query()
            ->where('business_id', $business->id)
            ->with(['projects' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'currency' => $client->billing_currency,
                'projects' => $client->projects->map(fn (Project $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'clients' => $clients]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<RecurringLineTemplate>
     */
    private function scopedToBusiness(int $businessId)
    {
        return RecurringLineTemplate::query()->where(function ($q) use ($businessId): void {
            $q->whereHas('client', fn ($c) => $c->where('business_id', $businessId))
                ->orWhereHas('project.client', fn ($c) => $c->where('business_id', $businessId));
        });
    }

    private function presentWindow(RecurringLineTemplate $template): string
    {
        $from = $template->active_from->format('M Y');

        return $template->active_to === null
            ? e($from).' <span class="text-default-400">onward</span>'
            : e($from.' – '.$template->active_to->format('M Y'));
    }

    /**
     * Whether this item is billing right now — an ended or not-yet-started
     * template still exists but contributes nothing, which is easy to miss.
     */
    private function presentStatus(RecurringLineTemplate $template): string
    {
        $today = now()->startOfDay();

        if ($template->active_from->greaterThan($today)) {
            return '<span class="badge bg-info">Scheduled</span>';
        }

        if ($template->active_to !== null && $template->active_to->lessThan($today)) {
            return '<span class="badge bg-default">Ended</span>';
        }

        return '<span class="badge bg-success">Active</span>';
    }
}
