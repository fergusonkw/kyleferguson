<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProjectRequest extends FormRequest
{
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
            'markup_type' => ['nullable', Rule::enum(MarkupType::class)],
            'markup_value' => ['nullable', 'numeric', 'min:0', 'required_with:markup_type'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
