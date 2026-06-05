<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('do_project_uuid')->nullable();
            $table->string('markup_type')->nullable();
            $table->decimal('markup_value', 10, 4)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('terminated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->unique('do_project_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
