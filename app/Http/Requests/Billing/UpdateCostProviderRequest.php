<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCostProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('costProvider'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'token' => ['nullable', 'string', 'min:32', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.min' => 'Tokens must be at least 32 characters. Leave blank to keep the existing one.',
        ];
    }
}
