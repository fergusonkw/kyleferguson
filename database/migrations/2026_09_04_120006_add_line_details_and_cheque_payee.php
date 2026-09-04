<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three additions driven by real invoicing needs:
 *
 * - A line needs room to explain itself. A four-figure line reading only
 *   "Development" is not something a client can approve for payment.
 * - A one-off cost can be incurred in a currency the client is not billed in
 *   (a domain renewal priced in USD on a CAD invoice). The line records what
 *   was actually charged and the rate used, so the converted figure is
 *   defensible rather than asserted.
 * - Cheques need a payee, which is per-business, not a constant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('label');
            $table->decimal('source_amount', 14, 2)->nullable()->after('amount');
            $table->string('source_currency', 3)->nullable()->after('source_amount');
            $table->decimal('fx_rate_applied', 16, 8)->nullable()->after('source_currency');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('cheque_payable_to')->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['description', 'source_amount', 'source_currency', 'fx_rate_applied']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('cheque_payable_to');
        });
    }
};
