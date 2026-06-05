<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\Cadence;
use App\Models\Billing\RecurringLineTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRecurringLineTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RecurringLineTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', 'exists:clients,id', 'required_without:project_id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'cadence' => ['required', 'string', Rule::enum(Cadence::class)],
            'active_from' => ['required', 'date'],
            'active_to' => ['nullable', 'date', 'after:active_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required_without' => 'Either a client or a project must be selected.',
        ];
    }
}
