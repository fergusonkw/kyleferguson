<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class EnableMaintenanceModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:255'],
            'retry_after' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.max' => 'The message cannot exceed 255 characters.',
            'retry_after.integer' => 'Retry after must be a whole number of seconds.',
            'retry_after.min' => 'Retry after must be at least 60 seconds.',
            'retry_after.max' => 'Retry after cannot exceed 86,400 seconds (24 hours).',
        ];
    }
}
