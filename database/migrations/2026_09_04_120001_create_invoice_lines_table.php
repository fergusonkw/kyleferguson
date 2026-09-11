<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // Sub-items hang off a parent hosting line for transparency; markup
            // applies to the parent total, so children are display-only.
            $table->foreignId('parent_id')->nullable()
                ->constrained('invoice_lines')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('label');
            $table->string('line_type');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('cost_basis_usd', 14, 4)->nullable();
            $table->string('source_reference')->nullable();
            $table->boolean('is_display_only')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'display_order']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
