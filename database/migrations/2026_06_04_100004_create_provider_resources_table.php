<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_resource_id');
            $table->string('resource_type');
            $table->string('name')->nullable();
            $table->string('provider_project_uuid')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['cost_provider_id', 'provider_resource_id', 'resource_type'], 'provider_resources_unique');
            $table->index('project_id');
            $table->index('provider_project_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_resources');
    }
};
