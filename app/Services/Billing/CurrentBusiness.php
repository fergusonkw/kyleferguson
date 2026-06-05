<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Business;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;

/**
 * Session-scoped pointer to the business currently in context for the admin UI.
 * Defaults to the first available business when nothing is set.
 */
final class CurrentBusiness
{
    private const SESSION_KEY = 'billing.current_business_id';

    public function get(): ?Business
    {
        $id = Session::get(self::SESSION_KEY);

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            /** @var Business|null $business */
            $business = Business::find((int) $id);
            if ($business !== null) {
                return $business;
            }
        }

        $first = Business::query()->orderBy('name')->first();
        if ($first !== null) {
            $this->set($first);
        }

        return $first;
    }

    public function set(Business $business): void
    {
        Session::put(self::SESSION_KEY, $business->id);
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * @return Collection<int, Business>
     */
    public function available(): Collection
    {
        /** @var Collection<int, Business> $businesses */
        $businesses = Business::query()->orderBy('name')->get();

        return $businesses;
    }
}
