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
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number');
            $table->string('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status')->default('draft');
            $table->string('issue_currency', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Snapshots — an invoice must stay reproducible even after the
            // business rebrands, changes templates, or a rate source is replaced.
            $table->decimal('fx_rate_snapshot', 16, 8)->default(1);
            $table->string('fx_rate_source')->nullable();
            $table->string('fx_rate_period', 7)->nullable();
            $table->string('template_view_snapshot');
            $table->string('email_template_view_snapshot');
            $table->text('late_fee_terms_snapshot')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('client_snapshot')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('hosted_view_token', 64);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'invoice_number']);
            $table->unique('hosted_view_token');

            // Invoice generation is idempotent per client and period.
            $table->unique(['client_id', 'period'], 'invoices_client_period_unique');
            $table->index(['business_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
