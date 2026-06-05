<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('invoice_number');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');
            $table->char('issue_currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('fx_rate_snapshot', 16, 8)->default(1);
            $table->string('fx_rate_source')->default('bank_of_canada');
            $table->string('fx_rate_period', 7);
            $table->string('template_view_snapshot');
            $table->string('email_template_view_snapshot');
            $table->text('late_fee_terms_snapshot')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('hosted_view_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->unique(['client_id', 'period_start']);
            $table->index(['business_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
