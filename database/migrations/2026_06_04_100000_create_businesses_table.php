<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_email');
            $table->string('logo_path')->nullable();
            $table->string('brand_primary_color', 16)->nullable();
            $table->string('brand_secondary_color', 16)->nullable();
            $table->string('invoice_template_view')->default('admin-v2.billing.invoices.templates.default');
            $table->string('email_template_view')->default('emails.invoices.default');
            $table->string('invoice_number_prefix', 16)->default('INV-');
            $table->unsignedInteger('invoice_number_sequence')->default(1);
            $table->string('default_currency', 3)->default('CAD');
            $table->json('supported_currencies')->nullable();
            $table->string('fx_source')->default('bank_of_canada');
            $table->date('tax_registered_from')->nullable();
            $table->string('notification_email');
            $table->time('daily_reminder_time')->default('08:00:00');
            $table->text('late_fee_terms')->nullable();
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
