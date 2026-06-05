<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Jobs\Billing\DraftReminderDigestJob;
use App\Jobs\Billing\GenerateMonthlyDraftsJob;
use App\Mail\Billing\ClientInvoiceMail;
use App\Mail\Billing\DraftReminderDigest;
use App\Mail\Billing\InvoiceReadyForReview;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class CheckpointIMailablesAndHostedViewTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'notification_email' => 'ops@example.com',
        ]);

        $this->client = Client::factory()->for($this->business)->create([
            'contact_email' => 'client@example.com',
        ]);

        $this->invoice = Invoice::factory()->sent()->create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'hosted_view_token' => 'test-token-abc123',
        ]);
    }

    public function test_invoice_ready_for_review_mailable_uses_correct_subject(): void
    {
        Mail::fake();

        Mail::to('ops@example.com')->queue(new InvoiceReadyForReview($this->invoice));

        Mail::assertQueued(InvoiceReadyForReview::class, function (InvoiceReadyForReview $mail): bool {
            return str_contains($mail->envelope()->subject, $this->invoice->invoice_number);
        });
    }

    public function test_client_invoice_mail_uses_email_template_snapshot(): void
    {
        Mail::fake();

        Mail::to($this->client->contact_email)->queue(new ClientInvoiceMail($this->invoice));

        Mail::assertQueued(ClientInvoiceMail::class, function (ClientInvoiceMail $mail): bool {
            return $mail->content()->view === $this->invoice->email_template_view_snapshot;
        });
    }

    public function test_draft_reminder_digest_only_includes_old_drafts(): void
    {
        $clientA = Client::factory()->for($this->business)->create();
        $clientB = Client::factory()->for($this->business)->create();

        $oldDraft = Invoice::factory()->draft()->forPeriod('2026-05')->create([
            'business_id' => $this->business->id,
            'client_id' => $clientA->id,
            'created_at' => now()->subDays(2),
        ]);

        $newDraft = Invoice::factory()->draft()->forPeriod('2026-05')->create([
            'business_id' => $this->business->id,
            'client_id' => $clientB->id,
        ]);

        Mail::fake();

        app(DraftReminderDigestJob::class, ['businessId' => $this->business->id])->handle();

        Mail::assertQueued(DraftReminderDigest::class, function (DraftReminderDigest $mail) use ($oldDraft, $newDraft): bool {
            return $mail->drafts->contains('id', $oldDraft->id)
                && ! $mail->drafts->contains('id', $newDraft->id);
        });
    }

    public function test_draft_reminder_digest_skips_when_no_old_drafts(): void
    {
        Mail::fake();

        // New draft (< 1 day old) — should not trigger a reminder
        $freshClient = Client::factory()->for($this->business)->create();
        Invoice::factory()->draft()->forPeriod('2026-05')->create([
            'business_id' => $this->business->id,
            'client_id' => $freshClient->id,
        ]);

        app(DraftReminderDigestJob::class, ['businessId' => $this->business->id])->handle();

        Mail::assertNothingQueued();
    }

    public function test_generate_monthly_drafts_job_dispatches(): void
    {
        Bus::fake();

        GenerateMonthlyDraftsJob::dispatch($this->business->id, '2026-06');

        Bus::assertDispatched(
            GenerateMonthlyDraftsJob::class,
            fn (GenerateMonthlyDraftsJob $job): bool => $job->businessId === $this->business->id && $job->period === '2026-06'
        );
    }

    public function test_hosted_invoice_view_renders_with_valid_token(): void
    {
        $response = $this->get('/invoices/test-token-abc123');

        $response->assertOk();
        $response->assertSee($this->invoice->invoice_number);
        $response->assertSee($this->client->name);
    }

    public function test_hosted_invoice_view_returns_404_for_invalid_token(): void
    {
        $response = $this->get('/invoices/invalid-token-xyz');

        $response->assertNotFound();
    }

    public function test_hosted_view_shows_paid_status_banner(): void
    {
        $this->invoice->update(['status' => InvoiceStatus::Paid]);

        $response = $this->get('/invoices/test-token-abc123');

        $response->assertOk();
        $response->assertSee('paid in full');
    }
}
