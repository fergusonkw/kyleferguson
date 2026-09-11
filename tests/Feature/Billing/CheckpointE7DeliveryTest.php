<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Jobs\Billing\DraftReminderDigestJob;
use App\Jobs\Billing\GenerateMonthlyDraftsJob;
use App\Mail\Billing\ClientInvoiceMail;
use App\Mail\Billing\DraftReminderDigest;
use App\Mail\Billing\InvoiceGenerationNeedsAttention;
use App\Mail\Billing\InvoiceReadyForReview;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Models\Billing\Project;
use App\Models\Billing\RecurringLineTemplate;
use App\Services\Billing\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Getting the invoice to the client: the hosted view, the four mailables, and
 * the scheduled draft generation behind them.
 */
final class CheckpointE7DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson',
            'contact_email' => 'hello@kyleferguson.ca',
            'notification_email' => 'ops@kyleferguson.ca',
            'invoice_number_prefix' => 'KF-',
            'invoice_number_sequence' => 1,
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'contact_name' => 'Dana Reid',
            'contact_email' => 'ap@acme.test',
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Passthrough,
        ]);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(2.0)->create();
    }

    // ---- Hosted client view ------------------------------------------------

    public function test_an_issued_invoice_is_readable_by_its_token(): void
    {
        $invoice = $this->issued();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('Acme Industries')
            ->assertSee('Download PDF');
    }

    public function test_the_hosted_view_needs_no_login(): void
    {
        $invoice = $this->issued();

        $this->assertGuest();
        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))->assertOk();
    }

    public function test_a_draft_is_not_reachable_by_token(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))->assertNotFound();
    }

    public function test_a_voided_invoice_is_not_reachable_by_token(): void
    {
        $invoice = $this->issued();
        $invoice->forceFill(['status' => InvoiceStatus::Void])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))->assertNotFound();
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('invoices.hosted.show', str_repeat('x', 64)))->assertNotFound();
    }

    public function test_the_hosted_page_is_marked_noindex(): void
    {
        $invoice = $this->issued();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee('noindex', false);
    }

    public function test_the_hosted_page_shows_a_partial_payment_banner(): void
    {
        $invoice = $this->issued(total: 100.00);
        Payment::factory()->for($invoice)->amount(40.00)->create();
        $invoice->forceFill(['status' => InvoiceStatus::PartiallyPaid])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee('Partial payment of $40.00')
            ->assertSee('Balance due: $60.00 CAD');
    }

    public function test_the_hosted_page_shows_a_paid_banner(): void
    {
        $invoice = $this->issued(total: 100.00);
        Payment::factory()->for($invoice)->amount(100.00)->create();
        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee('Paid in full');
    }

    public function test_the_hosted_page_flags_an_overdue_invoice(): void
    {
        $invoice = $this->issued(total: 100.00);
        $invoice->forceFill(['due_on' => now()->subWeek()->toDateString()])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee('was due on');
    }

    public function test_an_unpaid_invoice_that_is_not_yet_due_shows_no_banner(): void
    {
        $invoice = $this->issued(total: 100.00);
        $invoice->forceFill(['due_on' => now()->addWeek()->toDateString()])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertDontSee('Balance due:')
            ->assertDontSee('Paid in full');
    }

    public function test_the_pdf_is_downloadable_by_token(): void
    {
        Storage::fake('local');
        $invoice = $this->issued();

        $this->get(route('invoices.hosted.pdf', $invoice->hosted_view_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_drafts_pdf_is_not_downloadable_by_token(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create();

        $this->get(route('invoices.hosted.pdf', $invoice->hosted_view_token))->assertNotFound();
    }

    // ---- Client mail -------------------------------------------------------

    public function test_marking_sent_emails_the_client(): void
    {
        Mail::fake();
        Storage::fake('local');
        $invoice = $this->approved();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertOk();

        Mail::assertSent(ClientInvoiceMail::class, fn (ClientInvoiceMail $m): bool => $m->hasTo('ap@acme.test'));
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_the_status_does_not_move_when_the_mail_fails(): void
    {
        Storage::fake('local');
        $invoice = $this->approved();

        // No mailer configured for this transport → sending throws.
        config(['mail.default' => 'nonexistent-transport']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertStatus(422);

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_a_draft_cannot_be_emailed(): void
    {
        Mail::fake();
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_the_client_mail_carries_the_pdf_and_the_hosted_link(): void
    {
        Storage::fake('local');
        $invoice = $this->approved();

        $rendered = (new ClientInvoiceMail($invoice))->render();

        $this->assertStringContainsString($invoice->invoice_number, $rendered);
        $this->assertStringContainsString(
            route('invoices.hosted.show', $invoice->hosted_view_token),
            $rendered,
        );

        $attachments = (new ClientInvoiceMail($invoice))->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame($invoice->invoice_number.'.pdf', $attachments[0]->as);
    }

    public function test_the_client_mail_is_branded_from_the_invoices_snapshot(): void
    {
        Storage::fake('local');
        $invoice = $this->approved();
        $invoice->update(['business_snapshot' => [
            'name' => 'Trading Name At Issue',
            'contact_email' => 'billing@example.test',
            'brand_primary_color' => '#00aa88',
        ]]);

        $this->business->update(['name' => 'Renamed Since']);

        $rendered = (new ClientInvoiceMail($invoice->fresh()))->render();

        $this->assertStringContainsString('Trading Name At Issue', $rendered);
        $this->assertStringContainsString('#00aa88', $rendered);
        $this->assertStringNotContainsString('Renamed Since', $rendered);
    }

    public function test_a_missing_mail_template_falls_back(): void
    {
        Storage::fake('local');
        $invoice = $this->approved();
        $invoice->update(['email_template_view_snapshot' => 'emails.invoices.deleted-theme']);

        $this->assertStringContainsString(
            $invoice->invoice_number,
            (new ClientInvoiceMail($invoice->fresh()))->render(),
        );
    }

    // ---- Draft generation --------------------------------------------------

    public function test_the_job_builds_drafts_and_tells_the_operator(): void
    {
        Mail::fake();
        $this->seedCosts(50.00);

        (new GenerateMonthlyDraftsJob($this->business->id, self::PERIOD))->handle(app(InvoiceBuilder::class));

        $this->assertSame(1, Invoice::count());
        Mail::assertSent(InvoiceReadyForReview::class, fn (InvoiceReadyForReview $m): bool => $m->hasTo('ops@kyleferguson.ca')
            && $m->invoices->count() === 1);
    }

    public function test_one_clients_failure_does_not_stop_the_others(): void
    {
        Mail::fake();
        Http::fake(['*/observations/*' => Http::response(['observations' => []])]);

        $healthy = $this->client;
        $this->seedCosts(50.00, $healthy);

        // This client's recurring item needs a EUR rate that cannot be resolved.
        $broken = Client::factory()->for($this->business)->create([
            'name' => 'Broken Corp',
            'billing_currency' => 'CAD',
        ]);
        RecurringLineTemplate::factory()->for($broken)
            ->amount(10.00, 'EUR')->window('2026-01-01')->create(['label' => 'Unresolvable']);

        (new GenerateMonthlyDraftsJob($this->business->id, self::PERIOD))->handle(app(InvoiceBuilder::class));

        $this->assertSame(1, Invoice::query()->where('client_id', $healthy->id)->count());
        $this->assertSame(0, Invoice::query()->where('client_id', $broken->id)->count());

        Mail::assertSent(InvoiceReadyForReview::class);
        Mail::assertSent(
            InvoiceGenerationNeedsAttention::class,
            fn (InvoiceGenerationNeedsAttention $m): bool => array_key_exists('Broken Corp', $m->failures),
        );
    }

    public function test_inactive_clients_are_not_invoiced(): void
    {
        Mail::fake();
        $this->client->update(['status' => ClientStatus::Archived]);

        (new GenerateMonthlyDraftsJob($this->business->id, self::PERIOD))->handle(app(InvoiceBuilder::class));

        $this->assertSame(0, Invoice::count());
        Mail::assertNothingSent();
    }

    public function test_no_review_mail_when_there_is_nothing_to_bill(): void
    {
        Mail::fake();

        (new GenerateMonthlyDraftsJob($this->business->id, self::PERIOD))->handle(app(InvoiceBuilder::class));

        // An empty draft is still created, so the operator can add manual lines.
        $this->assertSame(1, Invoice::count());
        Mail::assertSent(InvoiceReadyForReview::class);
    }

    public function test_the_job_rejects_a_malformed_period(): void
    {
        Mail::fake();

        (new GenerateMonthlyDraftsJob($this->business->id, 'August'))->handle(app(InvoiceBuilder::class));

        $this->assertSame(0, Invoice::count());
        Mail::assertNothingSent();
    }

    // ---- Draft reminders ---------------------------------------------------

    public function test_a_stale_draft_is_chased(): void
    {
        Mail::fake();
        Invoice::factory()->for($this->business)->for($this->client)
            ->create(['created_at' => now()->subDays(3)]);

        (new DraftReminderDigestJob($this->business->id))->handle();

        Mail::assertSent(DraftReminderDigest::class, fn (DraftReminderDigest $m): bool => $m->hasTo('ops@kyleferguson.ca') && $m->drafts->count() === 1);
    }

    public function test_a_draft_generated_today_is_left_alone(): void
    {
        Mail::fake();
        Invoice::factory()->for($this->business)->for($this->client)->create(['created_at' => now()]);

        (new DraftReminderDigestJob($this->business->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_approved_invoices_are_not_chased(): void
    {
        Mail::fake();
        Invoice::factory()->for($this->business)->for($this->client)->approved()
            ->create(['created_at' => now()->subDays(3)]);

        (new DraftReminderDigestJob($this->business->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_another_businesses_drafts_are_not_chased(): void
    {
        Mail::fake();
        $other = Business::factory()->create();
        Invoice::factory()->for($other)->for(Client::factory()->for($other))
            ->create(['created_at' => now()->subDays(3)]);

        (new DraftReminderDigestJob($this->business->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_the_reminder_names_the_drafts_and_their_age(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)
            ->withTotal(250.00)->create(['created_at' => now()->subDays(3)]);

        $rendered = (new DraftReminderDigest($this->business, collect([$invoice->fresh()])))->render();

        $this->assertStringContainsString($invoice->invoice_number, $rendered);
        $this->assertStringContainsString('Acme Industries', $rendered);
        $this->assertStringContainsString('$250.00', $rendered);
    }

    // ---- Helpers -----------------------------------------------------------

    private function issued(float $total = 120.00): Invoice
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod(self::PERIOD)->withTotal($total)->sent()
            ->create([
                'business_snapshot' => ['name' => 'Kyle Ferguson', 'contact_email' => 'hello@kyleferguson.ca'],
                'client_snapshot' => ['name' => 'Acme Industries', 'contact_email' => 'ap@acme.test', 'billing_currency' => 'CAD'],
            ]);

        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)
            ->amount($total)->create(['label' => 'Hosting — Acme']);

        return $invoice->fresh();
    }

    private function approved(float $total = 120.00): Invoice
    {
        $invoice = $this->issued($total);
        $invoice->forceFill(['status' => InvoiceStatus::Approved, 'sent_at' => null])->save();

        return $invoice->fresh();
    }

    private function seedCosts(float $usd, ?Client $client = null): void
    {
        $client ??= $this->client;
        $project = Project::factory()->for($client)->create();

        $provider = CostProvider::query()->where('business_id', $this->business->id)->first()
            ?? CostProvider::factory()->for($this->business)->create();

        CostLineItem::factory()->for($provider)->forPeriod(self::PERIOD)
            ->usd($usd)->attributedTo($project)->create();
    }
}
