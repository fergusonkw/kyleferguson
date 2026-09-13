<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per email the application sends, whichever mailer carried it.
 *
 * The auditable record of what went out: who it went to, what it said, what
 * it was about, and — once SMTP2Go's webhooks report back — what became of
 * it. `status` is the headline; the events behind it live in `email_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();

            // Which configured mailer handled it. "log" or "array" here means
            // the message was written down, not delivered.
            $table->string('mailer', 32)->nullable();
            $table->string('mailable_class')->nullable();

            // What the email was about — an invoice, for the client invoice
            // mail — so the record can list the emails that carried it.
            $table->nullableMorphs('related');
            $table->json('metadata')->nullable();

            $table->string('from_address', 320)->nullable();
            $table->string('to_address', 1000);
            $table->string('subject')->nullable();

            // sending → sent → delivered, or off into a bounce, rejection or
            // complaint. Only ever moves forward; see App\Enums\EmailStatus.
            $table->string('status', 20)->default('sending');

            // SMTP2Go's email_id from the send response. Its webhooks quote it
            // back, so it is what ties a delivery event to this row.
            $table->string('provider_message_id', 100)->nullable()->index();

            // The body exactly as sent, unless it carried a credential.
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->boolean('content_withheld')->default(false);
            $table->json('attachments')->nullable();

            $table->text('error')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
