<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Exceptions\Billing\InvalidInvoiceTransition;
use App\Models\AuditLog;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Services\Billing\InvoiceApprover;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The approval workflow and the rendered document.
 *
 * PDF rendering shells out to Chromium, so only one test actually produces a
 * PDF; the rest assert against the HTML the renderer feeds it, which is where
 * every content decision lives.
 */
final class CheckpointE2ApprovalAndPdfTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson',
            'legal_name' => 'Kyle Ferguson',
            'contact_email' => 'hello@kyleferguson.ca',
            'address' => 'Prince Edward Island, Canada',
            'payment_terms_days' => 14,
            'late_fee_terms' => '2% monthly interest applies after 30 days.',
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'name' => 'Acme Industries',
            'contact_name' => 'Dana Reid',
            'contact_email' => 'ap@acme.test',
            'billing_address' => '12 Example Street, Charlottetown PE',
            'billing_currency' => 'CAD',
        ]);
    }

    public function test_approving_a_draft_stamps_issue_and_due_dates(): void
    {
        $invoice = $this->billableInvoice();

        $approved = app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(InvoiceStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->issued_on);
        $this->assertSame(
            $approved->issued_on->copy()->addDays(14)->toDateString(),
            $approved->due_on->toDateString(),
        );
    }

    public function test_due_date_follows_the_businesses_payment_terms(): void
    {
        $this->business->update(['payment_terms_days' => 30]);
        $invoice = $this->billableInvoice();

        $approved = app(InvoiceApprover::class)->approve($invoice);

        $this->assertSame(30, (int) $approved->issued_on->diffInDays($approved->due_on));
    }

    public function test_an_empty_draft_cannot_be_approved(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create();

        $this->expectException(InvalidInvoiceTransition::class);
        $this->expectExceptionMessage('has no billable lines');

        app(InvoiceApprover::class)->approve($invoice);
    }

    public function test_a_draft_of_only_display_lines_cannot_be_approved(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create();
        $parent = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount(10)->create();
        $parent->update(['is_display_only' => true]);

        $this->expectException(InvalidInvoiceTransition::class);

        app(InvoiceApprover::class)->approve($invoice->fresh());
    }

    public function test_a_negative_total_cannot_be_approved(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->create(['total' => -25.00]);
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Credit)->amount(-25.00)->create();

        $this->expectException(InvalidInvoiceTransition::class);
        $this->expectExceptionMessage('negative total');

        app(InvoiceApprover::class)->approve($invoice);
    }

    public function test_a_draft_cannot_skip_straight_to_sent(): void
    {
        $invoice = $this->billableInvoice();

        $this->expectException(InvalidInvoiceTransition::class);
        $this->expectExceptionMessage('cannot move from draft to sent');

        app(InvoiceApprover::class)->markSent($invoice);
    }

    public function test_approved_moves_to_sent_and_records_when(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->approve($this->billableInvoice());

        $sent = $approver->markSent($invoice);

        $this->assertSame(InvoiceStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);
    }

    public function test_an_invoice_cannot_be_approved_twice(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->approve($this->billableInvoice());

        $this->expectException(InvalidInvoiceTransition::class);

        $approver->approve($invoice);
    }

    public function test_any_live_invoice_can_be_voided_with_a_reason(): void
    {
        $invoice = $this->billableInvoice();

        $voided = app(InvoiceApprover::class)->void($invoice, 'Duplicate of KF-00007');

        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertStringContainsString('Duplicate of KF-00007', $voided->notes);
    }

    public function test_a_voided_invoice_cannot_be_revived(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->void($this->billableInvoice());

        $this->expectException(InvalidInvoiceTransition::class);

        $approver->approve($invoice);
    }

    public function test_a_voided_invoice_keeps_its_number(): void
    {
        $invoice = $this->billableInvoice();
        $number = $invoice->invoice_number;

        app(InvoiceApprover::class)->void($invoice);

        $this->assertSame($number, $invoice->fresh()->invoice_number);
        $this->assertDatabaseHas('invoices', ['invoice_number' => $number]);
    }

    public function test_every_transition_is_written_to_the_audit_log(): void
    {
        $approver = app(InvoiceApprover::class);
        $invoice = $approver->approve($this->billableInvoice());
        $approver->markSent($invoice);

        $entries = AuditLog::query()->where('event', 'invoice_status_changed')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('draft', $entries[0]->new_values['from']);
        $this->assertSame('approved', $entries[0]->new_values['to']);
        $this->assertSame('sent', $entries[1]->new_values['to']);
    }

    public function test_payment_status_can_only_be_applied_from_an_issued_invoice(): void
    {
        $invoice = $this->billableInvoice();

        $this->expectException(InvalidInvoiceTransition::class);

        app(InvoiceApprover::class)->applyPaymentStatus($invoice, InvoiceStatus::Paid);
    }

    public function test_applying_the_status_it_already_has_is_a_no_op(): void
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)->sent()->create();

        $result = app(InvoiceApprover::class)->applyPaymentStatus($invoice, InvoiceStatus::Sent);

        $this->assertSame(InvoiceStatus::Sent, $result->status);
        $this->assertSame(0, AuditLog::query()->where('event', 'invoice_status_changed')->count());
    }

    public function test_rendered_html_uses_the_invoices_snapshots_not_the_live_records(): void
    {
        $invoice = $this->billableInvoice();
        $invoice->update([
            'business_snapshot' => ['name' => 'Old Trading Name', 'contact_email' => 'old@example.test'],
            'client_snapshot' => ['name' => 'Acme (as billed)', 'contact_email' => 'ap@acme.test'],
        ]);

        // Rename both records after issue; the document must not follow them.
        $this->business->update(['name' => 'Brand New Name']);
        $this->client->update(['name' => 'Renamed Client']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Old Trading Name', $html);
        $this->assertStringContainsString('Acme (as billed)', $html);
        $this->assertStringNotContainsString('Brand New Name', $html);
        $this->assertStringNotContainsString('Renamed Client', $html);
    }

    public function test_rendered_html_shows_lines_totals_and_currency(): void
    {
        $invoice = $this->billableInvoice();
        app(InvoiceApprover::class)->approve($invoice);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('Hosting — Acme', $html);
        $this->assertStringContainsString('$120.00', $html);
        $this->assertStringContainsString('CAD', $html);
        $this->assertStringContainsString('Amount Due', $html);
    }

    public function test_rendered_html_lists_sub_items_under_their_parent(): void
    {
        $invoice = $this->billableInvoice();
        $parent = $invoice->topLevelLines()->firstOrFail();
        InvoiceLine::factory()->subItemOf($parent)->amount(80)->create(['label' => 'Compute']);
        InvoiceLine::factory()->subItemOf($parent)->amount(40)->create(['label' => 'Database']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Compute', $html);
        $this->assertStringContainsString('Database', $html);
    }

    public function test_rendered_html_carries_the_late_fee_terms_snapshot(): void
    {
        $invoice = $this->billableInvoice();
        $invoice->update(['late_fee_terms_snapshot' => '2% monthly interest applies after 30 days.']);

        $this->assertStringContainsString(
            '2% monthly interest applies after 30 days.',
            app(InvoicePdfRenderer::class)->html($invoice->fresh()),
        );
    }

    public function test_rendered_html_shows_the_balance_once_a_payment_lands(): void
    {
        $invoice = $this->billableInvoice();
        app(InvoiceApprover::class)->approve($invoice);
        Payment::factory()->for($invoice)->amount(20.00)->create();

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Balance Due', $html);
        $this->assertStringContainsString('$100.00', $html);
    }

    public function test_a_void_invoice_renders_a_void_stamp(): void
    {
        $invoice = $this->billableInvoice();
        app(InvoiceApprover::class)->void($invoice);

        $this->assertStringContainsString('Void', app(InvoicePdfRenderer::class)->html($invoice->fresh()));
    }

    public function test_brand_colours_come_from_the_snapshot(): void
    {
        $invoice = $this->billableInvoice();
        $invoice->update(['business_snapshot' => [
            'name' => 'Kyle Ferguson',
            'brand_primary_color' => '#00aa88',
            'brand_secondary_color' => '#101820',
        ]]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('#00aa88', $html);
        $this->assertStringContainsString('#101820', $html);
    }

    public function test_a_missing_template_falls_back_rather_than_failing(): void
    {
        $invoice = $this->billableInvoice();
        $invoice->update(['template_view_snapshot' => 'admin-v2.billing.invoices.templates.deleted-theme']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString($invoice->invoice_number, $html);
    }

    /**
     * The only test that actually drives Chromium.
     *
     * @group slow
     */
    public function test_the_invoice_renders_to_a_real_pdf(): void
    {
        $invoice = $this->billableInvoice();
        app(InvoiceApprover::class)->approve($invoice);

        $path = app(InvoicePdfRenderer::class)->store($invoice->fresh());
        $disk = Storage::disk('local');

        $this->assertTrue($disk->exists($path));
        $this->assertSame("invoices/{$this->business->id}/{$invoice->invoice_number}.pdf", $path);
        $this->assertStringStartsWith('%PDF-', (string) $disk->get($path));
        $this->assertGreaterThan(1000, $disk->size($path));
        $this->assertSame($path, $invoice->fresh()->pdf_path);

        $disk->delete($path);
    }

    private function billableInvoice(): Invoice
    {
        $invoice = Invoice::factory()->for($this->business)->for($this->client)
            ->withTotal(120.00)
            ->create([
                'business_snapshot' => [
                    'name' => $this->business->name,
                    'legal_name' => $this->business->legal_name,
                    'contact_email' => $this->business->contact_email,
                    'address' => $this->business->address,
                ],
                'client_snapshot' => [
                    'name' => $this->client->name,
                    'contact_name' => $this->client->contact_name,
                    'contact_email' => $this->client->contact_email,
                    'billing_address' => $this->client->billing_address,
                    'billing_currency' => 'CAD',
                ],
            ]);

        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)
            ->amount(120.00)->create(['label' => 'Hosting — Acme']);

        return $invoice->fresh();
    }
}
