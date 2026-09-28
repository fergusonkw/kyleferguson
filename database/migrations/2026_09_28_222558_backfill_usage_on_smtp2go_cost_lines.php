<?php

declare(strict_types=1);

use App\Models\Billing\CostLineItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Invoices now show what a metered service reported consuming, read from a
 * `usage` block on the cost line's metadata. SMTP2GO lines written before that
 * block existed carry the same figures under the sync's own `cycle_*` keys, so
 * they are translated once here.
 *
 * Without this, usage would only appear on periods a later sync happens to
 * touch — which for a closed month is never.
 *
 * Not reversible: it adds a derived key and removes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        CostLineItem::query()
            ->whereNotNull('metadata')
            ->chunkById(500, function ($lines): void {
                foreach ($lines as $line) {
                    $metadata = $line->metadata ?? [];

                    if (isset($metadata['usage']) || ! isset($metadata['cycle_used'])) {
                        continue;
                    }

                    $max = (int) ($metadata['cycle_max'] ?? 0);

                    $metadata['usage'] = [
                        'label' => '',
                        'used' => (float) $metadata['cycle_used'],
                        'included' => $max > 0 ? (float) $max : null,
                        'unit' => 'emails',
                    ];

                    $line->updateQuietly(['metadata' => $metadata]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
