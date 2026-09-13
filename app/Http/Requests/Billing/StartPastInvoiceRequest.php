<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Invoice;
use App\Services\Billing\BillingPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a past invoice: which client, and which month it billed.
 */
final class StartPastInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /**
     * @return array<string, array<int, string>>
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
            'client_id.required' => 'Choose the client the invoice was for.',
            'period.required' => 'Choose the month the invoice billed.',
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

    public function period(): string
    {
        return (string) $this->validated('period');
    }
}
