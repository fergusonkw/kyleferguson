<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice needs a due date, which means the business needs payment terms.
 * The invoice template already advertised "Net 14", so that is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->default(14)->after('late_fee_terms');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('payment_terms_days');
        });
    }
};
