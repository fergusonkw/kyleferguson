<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Invoice;
use App\Services\Billing\BillingPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class GenerateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'period' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Choose the client to generate a draft for.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! BillingPeriod::isValid((string) $this->input('period'))) {
                $validator->errors()->add('period', 'The billing period must be in YYYY-MM format.');
            }
        });
    }
}
