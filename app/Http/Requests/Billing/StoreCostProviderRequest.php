<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\CostProviderSlug;
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
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
