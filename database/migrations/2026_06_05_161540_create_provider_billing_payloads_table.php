<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_billing_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_provider_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->json('raw_payload');
            $table->string('content_hash', 64); // SHA-256 hex
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['cost_provider_id', 'period', 'content_hash'], 'billing_payloads_provider_period_hash_unique');
            $table->index(['cost_provider_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_billing_payloads');
    }
};
