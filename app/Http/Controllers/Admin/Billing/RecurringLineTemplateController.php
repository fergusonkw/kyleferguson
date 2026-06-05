<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\Cadence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreRecurringLineTemplateRequest;
use App\Http\Requests\Billing\UpdateRecurringLineTemplateRequest;
use App\Models\Billing\Client;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class RecurringLineTemplateController extends Controller
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function index(): View
    {
        $this->authorize('viewAny', RecurringLineTemplate::class);

        $business = $this->currentBusiness->get();

        $clients = $business !== null
            ? Client::query()->where('business_id', $business->id)->orderBy('name')->get()
            : collect();

        return view('admin-v2.billing.recurring-line-templates.index', [
            'cadenceOptions' => collect(Cadence::cases())
                ->mapWithKeys(fn (Cadence $c): array => [$c->value => ucfirst($c->value)])
                ->all(),
            'clients' => $clients,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RecurringLineTemplate::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $search = (string) $request->input('search.value', '');

        $query = RecurringLineTemplate::query()->with(['client', 'project']);

        if ($business !== null) {
            $clientIds = $business->clients()->pluck('id');
            $query->where(function ($q) use ($clientIds): void {
                $q->whereIn('client_id', $clientIds)
                    ->orWhereHas('project', fn ($p) => $p->whereIn('client_id', $clientIds));
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('label', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $total = (clone $query)->count();
        $templates = $query->orderBy('label')->skip($start)->take($length)->get();

        $rows = $templates->map(fn (RecurringLineTemplate $t): array => [
            'id' => $t->id,
            'label' => e($t->label),
            'client' => $t->client ? e($t->client->name) : '—',
            'project' => $t->project ? e($t->project->name) : '—',
            'amount' => e($t->currency).' '.number_format($t->amount, 2),
            'cadence' => ucfirst($t->cadence->value),
            'active_from' => $t->active_from->format('Y-m-d'),
            'active_to' => $t->active_to?->format('Y-m-d') ?? '—',
            'actions' => view('admin-v2.billing.recurring-line-templates.partials.actions', ['template' => $t])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $rows,
        ]);
    }

    public function store(StoreRecurringLineTemplateRequest $request): JsonResponse
    {
        $template = RecurringLineTemplate::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Recurring template created.',
            'data' => $template,
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
                'active_from' => $recurringLineTemplate->active_from->format('Y-m-d'),
                'active_to' => $recurringLineTemplate->active_to?->format('Y-m-d'),
            ],
        ]);
    }

    public function update(UpdateRecurringLineTemplateRequest $request, RecurringLineTemplate $recurringLineTemplate): JsonResponse
    {
        $recurringLineTemplate->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Recurring template updated.',
            'data' => $recurringLineTemplate->fresh(),
        ]);
    }

    public function destroy(RecurringLineTemplate $recurringLineTemplate): JsonResponse
    {
        $this->authorize('delete', $recurringLineTemplate);

        $recurringLineTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recurring template deleted.',
        ]);
    }
}
