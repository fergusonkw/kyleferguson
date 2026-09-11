<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\LegalEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLegalEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('legalEntity'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entityId = $this->route('legalEntity')->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('legal_entities', 'name')->ignore($entityId)],
            'entity_type' => ['required', Rule::enum(LegalEntityType::class)],
            'tax_registered_from' => ['nullable', 'date'],
            'threshold_warning_percent' => ['required', 'integer', 'min:1', 'max:99'],
            'associate_ids' => ['nullable', 'array'],
            'associate_ids.*' => ['integer', 'exists:legal_entities,id', Rule::notIn([$entityId])],
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
            'associate_ids.*.not_in' => 'An entity cannot be associated with itself.',
        ];
    }
}
