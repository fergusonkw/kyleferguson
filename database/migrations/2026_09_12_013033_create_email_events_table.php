<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What SMTP2Go reported about each email, as it reported it.
 *
 * Append-only: a row is never edited, and the payload is kept whole (bar the
 * credential in `auth`) so the headline status on `email_messages` can always
 * be checked against the reports it was derived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();

            // SMTP2Go's own event name: processed, delivered, bounce, open, …
            $table->string('event', 32);
            $table->string('recipient', 320)->nullable();
            $table->json('payload');

            // SMTP2Go retries a webhook it thinks failed, so the same report
            // can arrive twice. The hash of the payload recognises the repeat.
            $table->char('fingerprint', 64)->unique();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['email_message_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
