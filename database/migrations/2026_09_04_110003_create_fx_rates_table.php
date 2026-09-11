<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('currency_from', 3);
            $table->string('currency_to', 3);
            $table->string('period', 7);
            $table->decimal('rate', 16, 8);
            $table->string('source');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['currency_from', 'currency_to', 'period', 'source'], 'fx_rates_pair_period_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
