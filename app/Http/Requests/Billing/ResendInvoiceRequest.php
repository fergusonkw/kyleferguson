<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

final class ResendInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('send', $this->invoice());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $issuedOn = $this->invoice()->issued_on;

        return [
            'recipient' => ['required', 'email', 'max:255'],

            // The same floor the due-date control applies: a date before the
            // issue date would be past due the moment the client received it.
            'due_on' => array_values(array_filter([
                'nullable',
                'date',
                $issuedOn !== null ? 'after_or_equal:'.$issuedOn->toDateString() : null,
            ])),

            'replace_link' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.required' => 'Enter the address to send the invoice to.',
            'recipient.email' => 'That is not a valid email address.',
            'due_on.after_or_equal' => 'The due date cannot fall before the issue date of '
                .($this->invoice()->issued_on?->format('F j, Y') ?? 'the invoice').'.',
        ];
    }

    public function recipient(): string
    {
        return trim((string) $this->validated('recipient'));
    }

    public function dueOn(): ?Carbon
    {
        $value = $this->validated('due_on');

        return filled($value) ? Carbon::parse($value)->startOfDay() : null;
    }

    public function replacesLink(): bool
    {
        return $this->boolean('replace_link');
    }

    private function invoice(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $invoice;
    }
}
