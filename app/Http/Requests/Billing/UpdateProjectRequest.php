<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use App\Http\Requests\Billing\Concerns\ValidatesMarkup;
use App\Http\Requests\Billing\Concerns\ValidatesTermination;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProjectRequest extends FormRequest
{
    use ValidatesMarkup;
    use ValidatesTermination;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'do_project_uuid' => [
                'nullable', 'string', 'max:64',
                Rule::unique('projects', 'do_project_uuid')->ignore($project->id),
            ],
            'status' => ['required', Rule::enum(ProjectStatus::class)],

            // Billing reads this, not the status: it decides the period from
            // which a project's standing charges stop.
            'terminated_at' => ['nullable', 'date'],
            'markup_type' => ['nullable', Rule::enum(MarkupType::class)],
            'markup_value' => ['nullable', 'numeric', 'min:0'],
            'markup_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateMarkupComponents(
                $v, $this->input('markup_type'), 'markup_value', 'markup_fee',
            );
            $this->validateTermination($v);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        return $this->resolveTermination(parent::validated());
    }
}
