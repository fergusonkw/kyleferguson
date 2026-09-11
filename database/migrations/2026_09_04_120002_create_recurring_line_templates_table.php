<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_line_templates', function (Blueprint $table): void {
            $table->id();

            // Attaches to a client (all their invoices) or narrows to one
            // project. Exactly one is set; enforced in the form request.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('cadence')->default('monthly');
            $table->date('active_from');
            $table->date('active_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'active_from']);
            $table->index(['project_id', 'active_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_line_templates');
    }
};
