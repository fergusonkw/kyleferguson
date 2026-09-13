<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an invoice the client received before this system kept the books,
 * entered afterwards for the record.
 *
 * Such an invoice is written by hand from the original — never built from
 * costs — and is recorded as issued on its real date, without an email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_historical')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('is_historical');
        });
    }
};
