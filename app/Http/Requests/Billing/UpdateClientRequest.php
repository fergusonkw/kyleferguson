<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\MarkupType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'billing_currency' => [
                'required', 'string', 'size:3',
                function (string $attribute, mixed $value, Closure $fail) use ($client): void {
                    if (! in_array($value, $client->business->supported_currencies ?? [], true)) {
                        $fail("The {$value} currency is not supported by this business.");
                    }
                },
            ],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'default_markup_type' => ['required', Rule::enum(MarkupType::class)],
            'default_markup_value' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
