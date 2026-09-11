<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Role as RoleEnum;
use App\Mail\Billing\ClientInvoiceMail;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceDocument;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Tests\TestCase;

/**
 * PDFs are rendered when asked for; nothing about an invoice lives on disk.
 *
 * A host like Laravel Cloud wipes its filesystem on deploy, so the durable
 * record of what a client was sent is the issued document captured into the
 * database at approval and on each resend. Downloads render the current copy
 * — so a paid invoice says Paid — while the email carries the captured one.
 */
final class CheckpointG3OnDemandPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PAID_STAMP = '<div class="status-stamp">Paid</div>';

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Pdf::fake();

        $this->business = Business::factory()->create(['name' => 'Kyle Ferguson', 'payment_terms_days' => 14]);
        $this->client = Client::factory()->for($this->business)->create(['billing_currency' => 'CAD']);
    }

    public function test_approval_captures_the_document_as_issued(): void
    {
        $invoice = $this->approvedInvoice();

        $document = $invoice->issuedDocument;

        $this->assertNotNull($document);
        $this->assertSame(InvoiceDocumentReason::Approved, $document->reason);
        $this->assertStringContainsString($invoice->invoice_number, $document->html);
        $this->assertStringContainsString($invoice->due_on->format('F j, Y'), $document->html);
    }

    public function test_approving_writes_nothing_to_disk_and_renders_no_pdf(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.approve', $this->draftInvoice()))
            ->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles());
        Pdf::assertNotQueued();
    }

    public function test_the_captured_document_is_self_contained(): void
    {
        // It must reprint without the app: no stylesheet or font fetched from
        // anywhere at render time.
        $html = $this->approvedInvoice()->issuedDocument->html;

        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_the_captured_document_does_not_change_when_the_invoice_is_paid(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

        $this->assertStringNotContainsString(self::PAID_STAMP, $invoice->fresh()->issuedDocument->html);
        $this->assertStringContainsString(self::PAID_STAMP, app(InvoicePdfRenderer::class)->html($invoice->fresh()));
    }

    public function test_the_admin_download_is_the_current_copy(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Pdf::assertSee(self::PAID_STAMP);
    }

    public function test_the_as_issued_download_is_the_captured_document(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->documents()->create([
            'reason' => InvoiceDocumentReason::Resent,
            'html' => '<p>as last sent to the client</p>',
        ]);

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.pdf.issued', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('(as issued).pdf', (string) $response->headers->get('Content-Disposition'));
        Pdf::assertSee('<p>as last sent to the client</p>');
    }

    public function test_an_invoice_with_no_captured_document_offers_its_current_copy(): void
    {
        // Issued before documents were kept.
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->sent()->withTotal(50)->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.pdf.issued', $invoice))
            ->assertOk();

        Pdf::assertSee($invoice->invoice_number);
    }

    public function test_the_as_issued_download_needs_billing_access(): void
    {
        $invoice = $this->approvedInvoice();

        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.billing.invoices.pdf.issued', $invoice))
            ->assertForbidden();
    }

    public function test_the_client_download_is_the_current_copy(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();

        $this->get(route('invoices.hosted.pdf', $invoice->hosted_view_token))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Pdf::assertSee(self::PAID_STAMP);
    }

    public function test_the_invoice_email_attaches_the_document_as_issued(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->documents()->create([
            'reason' => InvoiceDocumentReason::Resent,
            'html' => '<p>the copy on record</p>',
        ]);

        $this->renderAttachments(new ClientInvoiceMail($invoice->fresh()));

        Pdf::assertSee('<p>the copy on record</p>');
    }

    public function test_sending_emails_the_document_captured_at_approval(): void
    {
        Mail::fake();
        $invoice = $this->approvedInvoice();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.sent', $invoice))
            ->assertOk();

        Mail::assertSent(ClientInvoiceMail::class, function (ClientInvoiceMail $mail): bool {
            $this->renderAttachments($mail);

            return true;
        });

        Pdf::assertSee($invoice->invoice_number);
        $this->assertSame(1, $invoice->documents()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_show_page_offers_the_as_issued_copy_once_approved(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->draftInvoice('2026-07');

        $this->actingAs($admin)
            ->get(route('admin.billing.invoices.show', $draft))
            ->assertOk()
            ->assertDontSee(route('admin.billing.invoices.pdf.issued', $draft));

        $approved = $this->approvedInvoice();

        $this->actingAs($admin)
            ->get(route('admin.billing.invoices.show', $approved))
            ->assertOk()
            ->assertSee(route('admin.billing.invoices.pdf.issued', $approved))
            ->assertSee('As issued');
    }

    public function test_the_document_html_never_appears_in_json(): void
    {
        $document = InvoiceDocument::factory()->create();

        $this->assertArrayNotHasKey('html', $document->toArray());
    }

    public function test_a_real_pdf_renders_from_the_captured_document(): void
    {
        // The only test here that drives Chromium.
        Pdf::swap(app(PdfBuilder::class));

        $invoice = $this->approvedInvoice();

        $pdf = app(InvoicePdfRenderer::class)->issuedPdf($invoice);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function draftInvoice(?string $period = null): Invoice
    {
        $factory = Invoice::factory()->for($this->business)->for($this->client)->withTotal(120.00);
        $invoice = ($period !== null ? $factory->forPeriod($period) : $factory)->create();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(120.00)->create();

        return $invoice->fresh();
    }

    private function approvedInvoice(): Invoice
    {
        return app(InvoiceApprover::class)->approve($this->draftInvoice())->fresh();
    }

    /**
     * Mail::fake() never builds attachments, so resolve them by hand to make
     * the renderer run.
     */
    private function renderAttachments(ClientInvoiceMail $mail): void
    {
        foreach ($mail->attachments() as $attachment) {
            $attachment->attachWith(fn () => null, fn ($data) => $data());
        }
    }
}
