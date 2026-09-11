<?php

declare(strict_types=1);

use App\Enums\Billing\LegalEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hangs every business off a legal entity and moves GST/HST registration to it.
 *
 * Existing businesses were all trade names of one sole proprietor, so they are
 * gathered under a single entity named after the first of them. Registration
 * moves with them: the earliest date any of them carried becomes the entity's,
 * since a registration covers every trade name the person operates.
 *
 * The column stays nullable in the schema so this backfill can run; the admin
 * forms require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('legal_entity_id')->nullable()->after('id')
                ->constrained()->restrictOnDelete();
        });

        $businesses = DB::table('businesses')->orderBy('id')->get();

        if ($businesses->isNotEmpty()) {
            $first = $businesses->first();
            $now = now();

            $entityId = DB::table('legal_entities')->insertGetId([
                'name' => $first->legal_name ?: $first->name,
                'entity_type' => LegalEntityType::SoleProprietorship->value,
                'tax_registered_from' => $businesses->pluck('tax_registered_from')->filter()->min(),
                'threshold_warning_percent' => 80,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('businesses')->update(['legal_entity_id' => $entityId]);
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('tax_registered_from');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->date('tax_registered_from')->nullable();
        });

        foreach (DB::table('legal_entities')->whereNotNull('tax_registered_from')->get() as $entity) {
            DB::table('businesses')
                ->where('legal_entity_id', $entity->id)
                ->update(['tax_registered_from' => $entity->tax_registered_from]);
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legal_entity_id');
        });
    }
};
