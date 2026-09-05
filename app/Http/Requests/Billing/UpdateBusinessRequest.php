<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Services\Billing\InvoiceTemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('business'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->route('business')->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('businesses', 'name')->ignore($businessId)],
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
}
