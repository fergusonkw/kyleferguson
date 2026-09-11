<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\MarkupType;
use App\Http\Requests\Billing\Concerns\ValidatesMarkup;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientRequest extends FormRequest
{
    use ValidatesMarkup;

    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'billing_currency' => [
                'required', 'string', 'size:3',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $business = Business::find($this->input('business_id'));
                    if ($business && ! in_array($value, $business->supported_currencies ?? [], true)) {
                        $fail("The {$value} currency is not supported by this business.");
                    }
                },
            ],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'default_markup_type' => ['required', Rule::enum(MarkupType::class)],
            'default_markup_value' => ['nullable', 'numeric', 'min:0'],
            'default_markup_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateMarkupComponents(
            $v, $this->input('default_markup_type'), 'default_markup_value', 'default_markup_fee',
        ));
    }
}
