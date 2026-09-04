<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use App\Http\Requests\Billing\Concerns\ValidatesMarkup;
use App\Models\Billing\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectRequest extends FormRequest
{
    use ValidatesMarkup;

    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'do_project_uuid' => ['nullable', 'string', 'max:64', 'unique:projects,do_project_uuid'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'markup_type' => ['nullable', Rule::enum(MarkupType::class)],
            'markup_value' => ['nullable', 'numeric', 'min:0'],
            'markup_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateMarkupComponents(
            $v, $this->input('markup_type'), 'markup_value', 'markup_fee',
        ));
    }
}
