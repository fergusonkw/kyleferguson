<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\RecurringCadence;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRecurringLineTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('recurringLineTemplate');

        return $template === null
            ? $this->user()->can('create', RecurringLineTemplate::class)
            : $this->user()->can('update', $template);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'cadence' => ['required', Rule::enum(RecurringCadence::class)],
            'active_from' => ['required', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Attaches to a whole client, or narrows to one of their projects.
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'A recurring item must charge something.',
            'active_to.after_or_equal' => 'The end date cannot fall before the start date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientId = $this->input('client_id');
            $projectId = $this->input('project_id');

            // Exactly one owner. Both would make it ambiguous which invoices
            // the item lands on; neither would leave it billing nobody.
            if (blank($clientId) && blank($projectId)) {
                $validator->errors()->add('client_id', 'Choose the client or the project this bills to.');

                return;
            }

            if (filled($clientId) && filled($projectId)) {
                $validator->errors()->add(
                    'project_id',
                    'Attach this to a client or to one project, not both.',
                );

                return;
            }

            // The cadence anchors on the start date, so a quarterly item
            // starting in March bills in March, June, September and December.
            // Saying so up front beats the operator discovering it on a draft.
            if (filled($projectId) && Project::query()->whereKey($projectId)->doesntExist()) {
                $validator->errors()->add('project_id', 'That project no longer exists.');
            }

            if (filled($clientId) && Client::query()->whereKey($clientId)->doesntExist()) {
                $validator->errors()->add('client_id', 'That client no longer exists.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'client_id' => filled($validated['client_id'] ?? null) ? (int) $validated['client_id'] : null,
            'project_id' => filled($validated['project_id'] ?? null) ? (int) $validated['project_id'] : null,
            'label' => $validated['label'],
            'amount' => $validated['amount'],
            'currency' => mb_strtoupper($validated['currency']),
            'cadence' => $validated['cadence'],
            'active_from' => $validated['active_from'],
            'active_to' => $validated['active_to'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
