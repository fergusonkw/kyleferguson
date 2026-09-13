<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a past invoice as issued, with its payment if it was paid.
 */
final class RecordPastInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'paid' => ['nullable', 'boolean'],
            'paid_on' => ['nullable', 'required_if_accepted:paid', 'date', 'before_or_equal:today'],
            'method' => ['nullable', 'required_if_accepted:paid', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'issued_on.required' => 'Enter the date on the original invoice.',
            'issued_on.before_or_equal' => 'A past invoice cannot have been issued in the future.',
            'due_on.after_or_equal' => 'The due date cannot fall before the issue date.',
            'paid_on.required_if_accepted' => 'Enter the date the payment arrived.',
            'paid_on.before_or_equal' => 'A payment cannot be dated in the future.',
            'method.required_if_accepted' => 'Choose how the client paid.',
        ];
    }

    public function issuedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('issued_on'))->startOfDay();
    }

    public function dueOn(): ?CarbonImmutable
    {
        $value = $this->validated('due_on');

        return filled($value) ? CarbonImmutable::parse((string) $value)->startOfDay() : null;
    }

    /**
     * Null when the invoice is still owed.
     */
    public function paidOn(): ?CarbonImmutable
    {
        return $this->boolean('paid')
            ? CarbonImmutable::parse((string) $this->validated('paid_on'))->startOfDay()
            : null;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->boolean('paid') ? PaymentMethod::from((string) $this->validated('method')) : null;
    }

    public function reference(): ?string
    {
        $reference = $this->validated('reference');

        return $this->boolean('paid') && filled($reference) ? trim((string) $reference) : null;
    }
}
