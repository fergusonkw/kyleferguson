<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an issued invoice contributes toward the small-supplier threshold, in
 * CAD, fixed at approval. Null until it can be valued — a non-CAD invoice
 * whose exchange rate is not yet available is counted once it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('supply_value_cad', 14, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('supply_value_cad');
        });
    }
};
