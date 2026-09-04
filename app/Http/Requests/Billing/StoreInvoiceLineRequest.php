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
            'description' => ['nullable', 'string', 'max:2000'],
            'line_type' => [
                'required',
                Rule::in(array_map(
                    fn (InvoiceLineType $t): string => $t->value,
                    InvoiceLineType::operatorEditable(),
                )),
            ],
            'amount' => ['required', 'numeric'],

            // A line may be incurred in a currency the client is not billed in;
            // it is converted at the invoice period's rate.
            'currency' => ['nullable', 'string', 'size:3', Rule::in($this->allowedCurrencies())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'line_type.in' => 'Derived lines cannot be added by hand — use an adjustment instead.',
            'currency.in' => 'That currency is not one this business supports. Add it to the business first.',
        ];
    }

    /**
     * The invoice's own currency plus whatever the business supports, so a
     * one-off cost can be entered in the currency it was actually charged in.
     *
     * @return list<string>
     */
    public function allowedCurrencies(): array
    {
        $invoice = $this->invoice();

        return collect([$invoice->issue_currency, $invoice->business->default_currency])
            ->merge($invoice->business->supported_currencies ?? [])
            ->filter()
            ->map(fn (string $c): string => mb_strtoupper($c))
            ->unique()
            ->values()
            ->all();
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
