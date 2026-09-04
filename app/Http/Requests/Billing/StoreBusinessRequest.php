<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Business;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Business::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:businesses,name'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_email' => ['required', 'email', 'max:255'],
            'notification_email' => ['required', 'email', 'max:255'],
            'brand_primary_color' => ['nullable', 'string', 'max:16'],
            'brand_secondary_color' => ['nullable', 'string', 'max:16'],
            'invoice_number_prefix' => ['required', 'string', 'max:16'],
            'default_currency' => ['required', 'string', 'size:3'],
            'supported_currencies' => ['required', 'array', 'min:1'],
            'supported_currencies.*' => ['string', 'size:3'],
            'fx_source' => ['required', 'string', 'max:64'],
            'tax_registered_from' => ['nullable', 'date'],
            'daily_reminder_time' => ['required', 'date_format:H:i'],
            'late_fee_terms' => ['nullable', 'string', 'max:2000'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'cheque_payable_to' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A business name is required.',
            'name.unique' => 'A business with this name already exists.',
            'contact_email.required' => 'A contact email is required.',
            'notification_email.required' => 'A notification email is required (where draft-invoice alerts are sent).',
            'default_currency.size' => 'Currency must be a 3-letter code (e.g. CAD).',
            'supported_currencies.required' => 'Select at least one supported currency.',
            'supported_currencies.min' => 'Select at least one supported currency.',
            'daily_reminder_time.date_format' => 'Reminder time must be in HH:MM format.',
        ];
    }
}
