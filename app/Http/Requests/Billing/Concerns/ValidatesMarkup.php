<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing\Concerns;

use App\Enums\Billing\MarkupType;
use Illuminate\Contracts\Validation\Validator;

/**
 * Markup is expressed as a percent plus a flat fee; each type uses one, both,
 * or neither. Requiring exactly the fields the chosen type uses stops a
 * "Fixed fee" markup being saved with no fee — which would silently bill the
 * client at cost.
 */
trait ValidatesMarkup
{
    protected function validateMarkupComponents(
        Validator $validator,
        ?string $type,
        string $percentField,
        string $feeField,
    ): void {
        $markup = MarkupType::tryFrom((string) $type);

        if ($markup === null) {
            return;
        }

        if ($markup->usesPercent() && ! $this->hasPositive($percentField)) {
            $validator->errors()->add(
                $percentField,
                'A '.mb_strtolower($markup->label()).' needs a percentage above zero.',
            );
        }

        if ($markup->usesFee() && ! $this->hasPositive($feeField)) {
            $validator->errors()->add(
                $feeField,
                'A '.mb_strtolower($markup->label()).' needs a fee above zero.',
            );
        }
    }

    private function hasPositive(string $field): bool
    {
        $value = $this->input($field);

        return $value !== null && $value !== '' && is_numeric($value) && (float) $value > 0;
    }
}
