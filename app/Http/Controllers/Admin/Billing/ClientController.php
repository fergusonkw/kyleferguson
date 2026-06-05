<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\MarkupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreClientRequest;
use App\Http\Requests\Billing\UpdateClientRequest;
use App\Models\Billing\Client;
use App\Services\AuditLogger;
use App\Services\Billing\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ClientController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Client::class);

        return view('admin-v2.billing.clients.index', [
            'markupOptions' => MarkupType::options(),
            'statusOptions' => collect(ClientStatus::cases())
                ->mapWithKeys(fn (ClientStatus $s): array => [$s->value => $s->label()])
                ->all(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        $business = $this->currentBusiness->get();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $search = (string) $request->input('search.value', '');

        $query = Client::query()->withCount('projects');
        if ($business !== null) {
            $query->where('business_id', $business->id);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%");
            });
        }

        $total = (clone $query)->count();
        $clients = $query->orderBy('name')->skip($start)->take($length)->get();

        $rows = $clients->map(fn (Client $c): array => [
            'id' => $c->id,
            'name' => e($c->name),
            'contact_email' => e($c->contact_email),
            'billing_currency' => e($c->billing_currency),
            'projects_count' => $c->projects_count,
            'status' => sprintf(
                '<span class="badge bg-%s">%s</span>',
                e($c->status->badgeColor()),
                e($c->status->label()),
            ),
            'markup' => $this->renderMarkup($c->default_markup_type, $c->default_markup_value),
            'actions' => view('admin-v2.billing.clients.partials.actions', ['client' => $c])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $rows,
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());
        $this->audit->logCreated($client, ['billing', 'client']);

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully.',
            'data' => $client,
        ]);
    }

    public function edit(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json([
            'success' => true,
            'client' => [
                'id' => $client->id,
                'business_id' => $client->business_id,
                'name' => $client->name,
                'contact_name' => $client->contact_name,
                'contact_email' => $client->contact_email,
                'billing_address' => $client->billing_address,
                'billing_currency' => $client->billing_currency,
                'status' => $client->status->value,
                'default_markup_type' => $client->default_markup_type->value,
                'default_markup_value' => $client->default_markup_value,
                'notes' => $client->notes,
                'supported_currencies' => $client->business->supported_currencies ?? ['CAD'],
            ],
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $original = $client->getRawOriginal();
        $client->update($request->validated());

        $markupChanged = $original['default_markup_type'] !== $client->default_markup_type->value
            || (float) $original['default_markup_value'] !== (float) $client->default_markup_value;

        $tags = ['billing', 'client'];
        if ($markupChanged) {
            $this->audit->logCritical('markup_changed', $client, $original, $client->getAttributes(), array_merge($tags, ['markup']));
        } else {
            $this->audit->logUpdated($client, $original, $tags);
        }

        return response()->json([
            'success' => true,
            'message' => 'Client updated successfully.',
            'data' => $client->fresh(),
        ]);
    }

    public function destroy(Client $client): JsonResponse
    {
        $this->authorize('delete', $client);

        if ($client->projects()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a client that still has projects attached.',
            ], 422);
        }

        $this->audit->logDeleted($client, ['billing', 'client']);
        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client deleted.',
        ]);
    }

    private function renderMarkup(MarkupType $type, ?string $value): string
    {
        return match ($type) {
            MarkupType::Percent => e($value ?? '0').'%',
            MarkupType::FixedFee => '$'.e($value ?? '0'),
            MarkupType::Hybrid => '$'.e($value ?? '0').' + %',
            MarkupType::Passthrough => '<em class="text-default-400">pass-through</em>',
        };
    }
}
