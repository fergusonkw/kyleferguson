<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_project_uuid')->nullable();
            $table->timestamp('observed_from');
            $table->timestamp('observed_to')->nullable();
            $table->timestamps();

            $table->index(['provider_resource_id', 'observed_from']);
            $table->index(['provider_resource_id', 'observed_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_assignments');
    }
};
