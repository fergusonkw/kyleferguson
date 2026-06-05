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
            $table->foreignId('source_payload_id')
                ->constrained('provider_billing_payloads')
                ->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('usd_amount', 12, 6);
            $table->decimal('usd_tax', 12, 6)->default(0);
            $table->string('category');
            $table->string('description')->nullable();
            $table->timestamp('derived_at');
            $table->timestamps();

            $table->index(['cost_provider_id', 'period']);
            $table->index(['project_id', 'period']);
            $table->index(['provider_resource_id', 'period']);
            $table->index('source_payload_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_line_items');
    }
};
