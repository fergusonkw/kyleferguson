<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Models\Billing\Invoice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInvoiceLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->invoice());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'line_type' => [
                'required',
                Rule::in(array_map(
                    fn (InvoiceLineType $t): string => $t->value,
                    InvoiceLineType::operatorEditable(),
                )),
            ],
            'amount' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'line_type.in' => 'Derived lines cannot be added by hand — use an adjustment instead.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = InvoiceLineType::tryFrom((string) $this->input('line_type'));
            $amount = (float) $this->input('amount');

            if ($type === null) {
                return;
            }

            if ($amount === 0.0) {
                $validator->errors()->add('amount', 'A line of zero changes nothing — enter an amount.');

                return;
            }

            // Charges must be positive and reductions negative; the controller
            // normalizes the sign, but a mismatch usually means a typo.
            if (! $type->isNegative() && $amount < 0) {
                $validator->errors()->add('amount', 'A '.$type->label().' line must be positive. Use a discount or credit to reduce the total.');
            }
        });
    }

    private function invoice(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $invoice;
    }
}
