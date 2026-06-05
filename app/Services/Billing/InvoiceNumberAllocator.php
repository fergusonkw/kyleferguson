<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Business;
use Illuminate\Support\Facades\DB;

final class InvoiceNumberAllocator
{
    public function nextNumber(Business $business): string
    {
        return DB::transaction(function () use ($business): string {
            $locked = Business::query()->lockForUpdate()->findOrFail($business->id);
            $sequence = $locked->invoice_number_sequence;
            $locked->increment('invoice_number_sequence');

            return $locked->invoice_number_prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }
}
