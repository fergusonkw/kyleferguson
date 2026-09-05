<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing\Concerns;

use App\Enums\Billing\ProjectStatus;
use Illuminate\Contracts\Validation\Validator;

/**
 * Keeps a project's termination date and its status from contradicting
 * each other.
 *
 * The date is what billing reads — it decides when standing charges stop — so
 * a project marked terminated with no date, or dated but still active, would
 * bill in a way the status does not describe.
 */
trait ValidatesTermination
{
    protected function validateTermination(Validator $validator): void
    {
        $isTerminated = $this->input('status') === ProjectStatus::Terminated->value;
        $terminatedAt = $this->input('terminated_at');

        if (! $isTerminated && filled($terminatedAt)) {
            $validator->errors()->add(
                'terminated_at',
                'Clear the termination date, or set the status to Terminated.',
            );
        }
    }

    /**
     * Stamp today when a project is terminated without a date given, and clear
     * a stale date when it is revived. Leaves an explicit date alone so a
     * project that ended last month can be recorded as it actually happened.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function resolveTermination(array $validated): array
    {
        $isTerminated = ($validated['status'] ?? null) === ProjectStatus::Terminated->value;

        if (! $isTerminated) {
            $validated['terminated_at'] = null;

            return $validated;
        }

        if (blank($validated['terminated_at'] ?? null)) {
            $validated['terminated_at'] = now()->toDateString();
        }

        return $validated;
    }
}
