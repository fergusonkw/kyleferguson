<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\Smtp2goRegion;
use App\Models\Billing\CostProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCostProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CostProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'slug' => ['required', Rule::enum(CostProviderSlug::class)],
            'display_name' => ['required', 'string', 'max:255'],
            'invoice_label' => ['nullable', 'string', 'max:255'],
            'invoice_description' => ['nullable', 'string', 'max:2000'],
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'enabled' => ['nullable', 'boolean'],

            // Account-per-client providers bill a whole client, so the link is
            // required for them and rejected for the rest.
            'client_id' => [
                Rule::requiredIf(fn (): bool => $this->isAccountPerClient()),
                Rule::excludeIf(fn (): bool => ! $this->isAccountPerClient()),
                'integer',
                Rule::exists('clients', 'id')->where('business_id', $this->input('business_id')),
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
            return [];
        }

        return [
            'region' => (string) $this->input('region'),
            'monthly_fee' => (string) $this->input('monthly_fee'),
            'fee_currency' => mb_strtoupper((string) $this->input('fee_currency')),
        ];
    }

    private function slug(): ?CostProviderSlug
    {
        return CostProviderSlug::tryFrom((string) $this->input('slug'));
    }

    private function isSmtp2go(): bool
    {
        return $this->slug() === CostProviderSlug::Smtp2go;
    }

    private function isAccountPerClient(): bool
    {
        return $this->slug()?->isAccountPerClient() ?? false;
    }
}
