<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ContactRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:10000'],
            'copyToSelf' => ['sometimes', 'boolean'],
            '_hp' => ['nullable', 'string'],
            '_t' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Required',
            'email.required' => 'Required',
            'email.email' => 'Enter a valid email',
            'message.required' => 'Tell me a little about it',
        ];
    }
}
