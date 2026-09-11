<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person or corporation behind one or more businesses.
 *
 * GST/HST attaches to the legal person, not to a trade name: a sole proprietor
 * trading as several businesses registers once and has one small-supplier
 * threshold across all of them. Associations record entities whose supplies
 * are counted together for that threshold (a corporation its owner controls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('entity_type', 32);
            $table->date('tax_registered_from')->nullable();
            $table->unsignedTinyInteger('threshold_warning_percent')->default(80);
            $table->string('threshold_alert_level', 32)->nullable();
            $table->timestamp('threshold_alerted_at')->nullable();
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('legal_entity_associations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('associated_legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'associated_legal_entity_id'], 'legal_entity_associations_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entity_associations');
        Schema::dropIfExists('legal_entities');
    }
};
