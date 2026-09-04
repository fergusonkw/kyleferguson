<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Business;
use Illuminate\Support\Facades\DB;

/**
 * Hands out invoice numbers from a per-business sequence.
 *
 * Numbers must never repeat or skip within a business — an accountant reading
 * a gap in the sequence has to be able to trust it means a voided invoice, not
 * a lost one. The row is locked for the increment so two concurrent draft
 * generations cannot read the same value.
 */
final class InvoiceNumberAllocator
{
    private const PAD_LENGTH = 5;

    public function allocate(Business $business): string
    {
        return DB::transaction(function () use ($business): string {
            /** @var Business $locked */
            $locked = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence = $locked->invoice_number_sequence;

            $locked->forceFill(['invoice_number_sequence' => $sequence + 1])->save();

            return $this->format($locked->invoice_number_prefix, $sequence);
        });
    }

    /**
     * What the next allocation would produce, without consuming it. For
     * previews only — never persist this.
     */
    public function peek(Business $business): string
    {
        return $this->format($business->invoice_number_prefix, $business->invoice_number_sequence);
    }

    private function format(string $prefix, int $sequence): string
    {
        return $prefix.str_pad((string) $sequence, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}
