<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity and rate for work billed by the hour or the unit.
 *
 * "Consulting $1,140" tells a client nothing they can check. "Consulting,
 * 12 hrs at $95.00" is a figure they can verify, which is the difference
 * between an invoice being paid and being queried.
 *
 * All three stay nullable: a derived hosting line has no meaningful quantity,
 * and a fixed-price item is a single amount by nature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 2)->nullable()->after('description');
            $table->string('unit', 32)->nullable()->after('quantity');
            $table->decimal('unit_rate', 14, 2)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'unit', 'unit_rate']);
        });
    }
};
