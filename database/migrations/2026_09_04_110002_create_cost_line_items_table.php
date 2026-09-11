<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_payload_id')->nullable()
                ->constrained('provider_billing_payloads')->nullOnDelete();
            $table->string('period', 7);
            $table->string('category');
            $table->string('description');
            $table->decimal('source_amount', 14, 4);
            $table->string('source_currency', 3);
            $table->decimal('usd_amount', 14, 4);
            $table->decimal('usd_tax', 14, 4)->default(0);
            $table->string('source_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->string('line_hash', 64);
            $table->timestamp('attributed_at')->nullable();
            $table->timestamp('derived_at');
            $table->timestamps();

            $table->unique(['cost_provider_id', 'period', 'line_hash'], 'cost_line_items_idempotency_unique');
            $table->index(['project_id', 'period']);
            $table->index(['cost_provider_id', 'period']);
            $table->index('provider_resource_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_line_items');
    }
};
