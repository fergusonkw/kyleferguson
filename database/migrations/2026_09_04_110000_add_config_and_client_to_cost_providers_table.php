<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_providers', function (Blueprint $table): void {
            $table->json('config')->nullable()->after('credentials');
            $table->foreignId('client_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cost_providers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('config');
        });
    }
};
