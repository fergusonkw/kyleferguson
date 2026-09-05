<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Billing\Business;
use App\Services\Billing\InvoiceTemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // The logo is inlined into every rendered invoice as a data URI,
            // so the cap is about document size as much as upload size.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],

            // Both columns are non-nullable with a default, so these are
            // `sometimes` rather than `nullable`: omitting them keeps the
            // default, but sending a blank one would store an empty view name.
            'invoice_template_view' => ['sometimes', 'required', 'string', Rule::in(array_keys(app(InvoiceTemplateRegistry::class)->invoiceTemplates()))],
            'email_template_view' => ['sometimes', 'required', 'string', Rule::in(array_keys(app(InvoiceTemplateRegistry::class)->emailTemplates()))],
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
