<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\Smtp2goRegion;
use App\Models\Billing\CostProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCostProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('costProvider'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'invoice_label' => ['nullable', 'string', 'max:255'],
            'invoice_description' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['nullable', 'boolean'],
            'token' => ['nullable', 'string', 'min:32', 'max:512'],

            'client_id' => [
                Rule::requiredIf(fn (): bool => $this->isAccountPerClient()),
                Rule::excludeIf(fn (): bool => ! $this->isAccountPerClient()),
                'integer',
                Rule::exists('clients', 'id')->where('business_id', $this->provider()->business_id),
            ],

            'region' => [
                Rule::requiredIf(fn (): bool => $this->isSmtp2go()),
                Rule::excludeIf(fn (): bool => ! $this->isSmtp2go()),
                Rule::enum(Smtp2goRegion::class),
            ],
            'monthly_fee' => [
                Rule::requiredIf(fn (): bool => $this->isSmtp2go()),
                Rule::excludeIf(fn (): bool => ! $this->isSmtp2go()),
                'numeric',
                'min:0',
            ],
            'fee_currency' => [
                Rule::requiredIf(fn (): bool => $this->isSmtp2go()),
                Rule::excludeIf(fn (): bool => ! $this->isSmtp2go()),
                'string',
                'size:3',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.min' => 'Tokens must be at least 32 characters. Leave blank to keep the existing one.',
            'client_id.required' => 'This provider bills a single client, so a client must be selected.',
            'client_id.exists' => 'The selected client does not belong to this business.',
            'monthly_fee.required' => 'SMTP2GO has no cost API, so the plan fee must be entered manually.',
            'fee_currency.size' => 'Use a 3-letter currency code, e.g. USD.',
        ];
    }

    /**
     * Provider-specific settings destined for `cost_providers.config`.
     *
     * @return array<string, mixed>
     */
    public function configPayload(): array
    {
        if (! $this->isSmtp2go()) {
            return $this->provider()->config ?? [];
        }

        return [
            'region' => (string) $this->input('region'),
            'monthly_fee' => (string) $this->input('monthly_fee'),
            'fee_currency' => mb_strtoupper((string) $this->input('fee_currency')),
        ];
    }

    private function provider(): CostProvider
    {
        /** @var CostProvider $provider */
        $provider = $this->route('costProvider');

        return $provider;
    }

    private function isSmtp2go(): bool
    {
        return $this->provider()->slug === CostProviderSlug::Smtp2go;
    }

    private function isAccountPerClient(): bool
    {
        return $this->provider()->slug->isAccountPerClient();
    }
}
