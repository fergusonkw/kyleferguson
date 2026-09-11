<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a provider's costs are named on a client invoice.
 *
 * Every provider cost was previously labelled "Hosting", which is wrong for
 * anything that is not hosting — an email service billed as hosting is a line
 * a client can reasonably query. `display_name` is the operator's name for the
 * account ("SMTP2Go — Acme"); these are what the *client* reads, which is a
 * different thing and usually a plainer one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_providers', function (Blueprint $table): void {
            $table->string('invoice_label')->nullable()->after('display_name');
            $table->text('invoice_description')->nullable()->after('invoice_label');
        });
    }

    public function down(): void
    {
        Schema::table('cost_providers', function (Blueprint $table): void {
            $table->dropColumn(['invoice_label', 'invoice_description']);
        });
    }
};
