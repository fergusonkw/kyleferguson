<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

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
        ];
    }
}
