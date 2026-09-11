<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\LegalEntityType;
use App\Models\Billing\LegalEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLegalEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LegalEntity::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:legal_entities,name'],
            'entity_type' => ['required', Rule::enum(LegalEntityType::class)],
            'tax_registered_from' => ['nullable', 'date'],
            'threshold_warning_percent' => ['required', 'integer', 'min:1', 'max:99'],
            'associate_ids' => ['nullable', 'array'],
            'associate_ids.*' => ['integer', 'exists:legal_entities,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A legal entity name is required.',
            'name.unique' => 'A legal entity with this name already exists.',
            'entity_type.required' => 'Choose what kind of legal entity this is.',
            'threshold_warning_percent.required' => 'Set the percentage of the threshold at which to warn.',
            'threshold_warning_percent.min' => 'The warning percentage must be between 1 and 99.',
            'threshold_warning_percent.max' => 'The warning percentage must be between 1 and 99.',
            'associate_ids.*.exists' => 'A selected associated entity does not exist.',
        ];
    }
}
