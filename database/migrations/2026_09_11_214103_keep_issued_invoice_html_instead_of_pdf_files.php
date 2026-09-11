<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices stop keeping PDF files and keep the documents they were issued as.
 *
 * PDFs are now rendered when asked for, so nothing has to survive on a host
 * whose disk is wiped on deploy. What does have to survive is the record of
 * what the client was sent: the fully rendered, self-contained HTML (fonts,
 * logo and styles all inlined), captured at approval and again on each resend.
 * Every capture is kept, so each send can be reproduced exactly. It lives in
 * the database, covered by the same backups as everything else, and reprints
 * identically however the templates change later.
 *
 * A table of its own rather than a column on `invoices`, so listing invoices
 * never drags a hundred-odd kilobytes of inlined fonts along per row.
 *
 * Existing PDF files are left where they are; nothing reads them any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $table->longText('html');
            $table->timestamps();

            $table->index(['invoice_id', 'id']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable()->after('voided_at');
        });

        Schema::dropIfExists('invoice_documents');
    }
};
