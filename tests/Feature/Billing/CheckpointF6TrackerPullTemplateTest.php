<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Mail\Billing\ClientInvoiceMail;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Models\Billing\Payment;
use App\Services\Billing\DocumentKitStore;
use App\Services\Billing\InvoicePdfRenderer;
use App\Services\Billing\InvoiceSnapshotter;
use App\Services\Billing\InvoiceTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A second business's invoice template.
 *
 * The template machinery was built for this — a Blade file dropped beside the
 * default becomes selectable — but the first real second template is what
 * proves it. These tests cover the discovery, the vendored stylesheet it
 * renders with, and the parity that matters most: everything the default
 * template says about an invoice, this one has to say too, or Tracker Pull's
 * clients silently receive less information than Kyle Ferguson's.
 */
final class CheckpointF6TrackerPullTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'admin-v2.billing.invoices.templates.tracker-pull';

    private const EMAIL_TEMPLATE = 'emails.invoices.tracker-pull';

    /**
     * Every invoice mail template, HTML and plain text. The greeting bug this
     * checkpoint found was in all four, so the guard covers all four.
     *
     * @var list<string>
     */
    private const MAIL_TEMPLATES = [
        self::EMAIL_TEMPLATE,
        self::EMAIL_TEMPLATE.'-text',
        'emails.invoices.default',
        'emails.invoices.default-text',
    ];

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::factory()->create([
            'name' => 'Tracker Pull',
            'legal_name' => 'Tracker Pull Media Inc.',
            'contact_email' => 'kyle@trackerpull.ca',
            'address' => "85 Inkerman Road, PO Box 1\nCrapaud, PEI  C0A 1J0",
            'cheque_payable_to' => 'Tracker Pull Media Inc.',
            'invoice_template_view' => self::TEMPLATE,
            'email_template_view' => self::EMAIL_TEMPLATE,
            'supported_currencies' => ['CAD'],
        ]);
    }

    public function test_the_registry_offers_the_template(): void
    {
        $registry = app(InvoiceTemplateRegistry::class);

        $this->assertArrayHasKey(self::TEMPLATE, $registry->invoiceTemplates());
        $this->assertSame('Tracker Pull', $registry->invoiceTemplates()[self::TEMPLATE]);
        $this->assertTrue($registry->invoiceTemplateExists(self::TEMPLATE));
    }

    public function test_the_vendored_kit_is_not_mistaken_for_a_template(): void
    {
        // The kit lives in a subdirectory of the templates folder; discovery
        // globs one level for Blade files, so a stylesheet must not appear as
        // something a business could select.
        foreach (array_keys(app(InvoiceTemplateRegistry::class)->invoiceTemplates()) as $view) {
            $this->assertStringNotContainsString('kit', $view);
        }
    }

    public function test_an_invoice_snapshots_and_renders_the_template(): void
    {
        $invoice = $this->invoice();

        $this->assertSame(self::TEMPLATE, $invoice->template_view_snapshot);

        $html = $this->render($invoice);

        $this->assertStringContainsString('<h1 class="title">Invoice</h1>', $html);
        $this->assertStringContainsString('<table class="items">', $html);
        $this->assertStringContainsString($invoice->invoice_number, $html);
    }

    public function test_the_render_inlines_the_vendored_kit_and_fetches_nothing(): void
    {
        $html = $this->render($this->invoice());

        // Straight out of the kit — proves the vendored file was inlined
        // rather than the template carrying a hand-copied fork of it.
        $this->assertStringContainsString('TRACKER PULL — DOCUMENT KIT', $html);
        $this->assertStringContainsString('table.items thead th', $html);

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringNotContainsString('<link', $html);
    }

    public function test_the_kit_is_vendored_and_current(): void
    {
        $this->assertTrue(
            app(DocumentKitStore::class)->isVendored('tracker-pull'),
            'Run `php artisan billing:sync-document-kit` to vendor the document kit.',
        );
    }

    public function test_the_provenance_header_is_not_treated_as_styling(): void
    {
        // --check compares the styling alone, so a commit upstream that leaves
        // the CSS alone must not read as drift.
        $kits = app(DocumentKitStore::class);

        $body = ".page { color: red; }\n";
        $withHeader = "/* Vendored from somewhere\n   a/b.css @ abc1234 */\n\n".$body;

        $this->assertSame($kits->body($withHeader), $kits->body($body));
    }

    public function test_an_invoice_still_renders_when_the_kit_is_absent(): void
    {
        // A plain document beats no document, the same promise the fonts make.
        $store = new DocumentKitStore(sys_get_temp_dir().DIRECTORY_SEPARATOR.'no-kit-here');

        $this->assertSame('', $store->cssFor(self::TEMPLATE));
        $this->assertFalse($store->isVendored('tracker-pull'));
    }

    public function test_the_default_template_is_given_no_kit(): void
    {
        $kits = app(DocumentKitStore::class);

        $this->assertSame('', $kits->cssFor('admin-v2.billing.invoices.templates.default'));
        $this->assertSame('', $kits->cssFor(null));
    }

    public function test_branding_overrides_the_kit_rather_than_editing_it(): void
    {
        $this->business->update([
            'brand_primary_color' => '#d22720',
            'brand_secondary_color' => '#111c26',
        ]);

        $html = $this->render($this->invoice());

        $this->assertStringContainsString('--brand-red: #d22720;', $html);
        $this->assertStringContainsString('--text: #111c26;', $html);

        // The kit's own token survives above the override, so the vendored
        // copy is still a clean diff against upstream.
        $this->assertStringContainsString('--brand-red: #DC2626;', $html);
    }

    public function test_an_unset_colour_leaves_the_kit_token_alone(): void
    {
        $html = $this->render($this->invoice());

        $this->assertStringNotContainsString('--brand-red: ;', $html);
        $this->assertStringNotContainsString('--text: ;', $html);
        $this->assertStringContainsString('--brand-red: #DC2626;', $html);
    }

    public function test_the_issuer_and_client_appear_as_parties(): void
    {
        $invoice = $this->invoice();

        $html = $this->render($invoice);

        $this->assertStringContainsString('Tracker Pull Media Inc.', $html);
        $this->assertStringContainsString('85 Inkerman Road, PO Box 1', $html);
        // A multi-line address gets a div per line rather than one run-on.
        $this->assertStringContainsString('<div>Crapaud, PEI  C0A 1J0</div>', $html);
        $this->assertStringContainsString($invoice->client_snapshot['name'], $html);
    }

    public function test_rate_columns_appear_only_when_a_line_is_metered(): void
    {
        $flat = $this->invoice();
        InvoiceLine::factory()->for($flat)->create(['label' => 'Hosting', 'amount' => 40]);

        $html = $this->render($flat->fresh());

        $this->assertStringNotContainsString('>Unit Price<', $html);
        $this->assertStringContainsString('>Amount<', $html);
    }

    public function test_a_metered_line_shows_its_quantity_and_rate(): void
    {
        $invoice = $this->invoice();
        InvoiceLine::factory()->for($invoice)->create([
            'label' => 'Development',
            'amount' => 1140,
            'quantity' => '12.00',
            'unit' => 'hrs',
            'unit_rate' => '95.00',
        ]);

        $html = $this->render($invoice->fresh());

        $this->assertStringContainsString('>Unit Price<', $html);
        $this->assertStringContainsString('12 hrs', $html);
        $this->assertStringContainsString('$95.00', $html);
    }

    public function test_a_metered_line_keeps_its_working_when_the_columns_collapse(): void
    {
        // One metered line among flat ones brings the columns back; a lone
        // display-only child must not, so the note carries the arithmetic.
        $invoice = $this->invoice();
        $line = InvoiceLine::factory()->for($invoice)->create([
            'label' => 'Development',
            'amount' => 1140,
            'quantity' => '12.00',
            'unit' => 'hrs',
            'unit_rate' => '95.00',
        ]);

        $this->assertSame('12 hrs × $95.00', $line->rateNote());
    }

    public function test_child_lines_are_listed_under_their_parent(): void
    {
        $invoice = $this->invoice();
        $parent = InvoiceLine::factory()->for($invoice)->create(['label' => 'Hosting', 'amount' => 60]);
        InvoiceLine::factory()->subItemOf($parent)->create(['label' => 'Droplet', 'amount' => 24]);

        $html = $this->render($invoice->fresh());

        $this->assertStringContainsString('Droplet $24.00', $html);
    }

    public function test_payments_produce_a_paid_row_and_a_balance(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['subtotal' => 500, 'total' => 500])->save();
        Payment::factory()->for($invoice)->amount(200)->create();

        $html = $this->render($invoice->fresh());

        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('−$200.00', $html);
        $this->assertStringContainsString('Balance Due (CAD)', $html);
        $this->assertStringContainsString('$300.00', $html);
    }

    public function test_an_unpaid_invoice_reads_as_a_total(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['subtotal' => 500, 'total' => 500])->save();

        $html = $this->render($invoice->fresh());

        $this->assertStringContainsString('Total (CAD)', $html);
        $this->assertStringNotContainsString('Balance Due', $html);
    }

    public function test_the_status_is_stamped_on_anything_but_a_sent_invoice(): void
    {
        $invoice = $this->invoice();

        $this->assertStringContainsString('<span class="badge">Draft</span>', $this->render($invoice));

        $invoice->forceFill(['status' => InvoiceStatus::Void])->save();
        $this->assertStringContainsString('<span class="badge alert">Void</span>', $this->render($invoice->fresh()));

        $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();
        $this->assertStringContainsString('<span class="badge ok">Paid</span>', $this->render($invoice->fresh()));
    }

    public function test_the_payment_details_carry_the_cheque_payee_and_the_reference(): void
    {
        $invoice = $this->invoice();

        $html = $this->render($invoice);

        $this->assertStringContainsString('kyle@trackerpull.ca', $html);
        $this->assertStringContainsString('Tracker Pull Media Inc.', $html);
        $this->assertStringContainsString('Please reference '.$invoice->invoice_number, $html);

        // A payee that already ends a sentence does not get a second full stop.
        $this->assertStringNotContainsString('Inc.</strong>.', $html);
    }

    public function test_a_payee_that_does_not_end_a_sentence_gets_its_full_stop(): void
    {
        $this->business->update(['cheque_payable_to' => 'Tracker Pull']);

        $this->assertStringContainsString(
            'Cheques payable to <strong>Tracker Pull</strong>.',
            $this->render($this->invoice()),
        );
    }

    public function test_late_fee_terms_are_printed_when_the_invoice_carries_them(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['late_fee_terms_snapshot' => '2% monthly on overdue balances.'])->save();

        $this->assertStringContainsString('2% monthly on overdue balances.', $this->render($invoice->fresh()));
    }

    public function test_an_invoice_with_no_lines_says_so(): void
    {
        $html = $this->render($this->invoice());

        $this->assertStringContainsString('No billable items for this period.', $html);
    }

    public function test_the_billing_period_is_shown(): void
    {
        $invoice = $this->invoice();

        $this->assertStringContainsString('Billing period', $this->render($invoice));
    }

    public function test_a_business_without_a_logo_falls_back_to_a_monogram(): void
    {
        $html = $this->render($this->invoice());

        $this->assertStringContainsString('<div class="monogram">TP</div>', $html);
    }

    public function test_an_svg_logo_is_accepted_and_inlined(): void
    {
        // A vector prints sharp at any size, and it is only ever emitted as an
        // <img src="data:...">, where scripts do not run.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>',
        );

        $this->actingAs($this->createAdmin())
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload(['logo' => $svg]))
            ->assertOk();

        $this->assertNotNull($this->business->fresh()->logo_id);

        $html = $this->render($this->invoice());

        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('<div class="monogram">', $html);
    }

    public function test_the_hosted_page_adds_a_client_bar_the_pdf_does_not(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'subtotal' => 500,
            'total' => 500,
            'issued_on' => now()->subDays(40),
            'due_on' => now()->subDays(10),
        ])->save();

        $this->get(route('invoices.hosted.show', $invoice->hosted_view_token))
            ->assertOk()
            ->assertSee('class="client-bar screen-only"', false)
            ->assertSee('Download PDF');

        // The printed document stays the canonical record. The rule for the
        // bar is always in the stylesheet; what must not appear is the markup.
        $pdfHtml = $this->render($invoice->fresh());

        $this->assertStringNotContainsString('<div class="client-bar', $pdfHtml);
        $this->assertStringNotContainsString('Download PDF', $pdfHtml);
    }

    /**
     * The only test here that actually drives Chromium.
     *
     * Worth the seconds: the vendored kit and the embedded Inter faces reach
     * the renderer as one bare HTML string with no document base, and a
     * stylesheet or a font that fails to resolve there degrades the document
     * silently rather than failing anything.
     *
     * @group slow
     */
    public function test_the_template_renders_to_a_real_pdf(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['subtotal' => 500, 'total' => 500])->save();
        InvoiceLine::factory()->for($invoice)->create(['label' => 'Season results tooling', 'amount' => 500]);

        $pdf = app(InvoicePdfRenderer::class)->pdf($invoice->fresh());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    // ---- The client email --------------------------------------------------

    public function test_the_registry_offers_the_email_template_but_not_its_text_companion(): void
    {
        $registry = app(InvoiceTemplateRegistry::class);

        $this->assertArrayHasKey(self::EMAIL_TEMPLATE, $registry->emailTemplates());
        $this->assertArrayNotHasKey(self::EMAIL_TEMPLATE.'-text', $registry->emailTemplates());
        $this->assertTrue($registry->emailTemplateExists(self::EMAIL_TEMPLATE));
    }

    public function test_the_mail_renders_the_template_the_invoice_snapshotted(): void
    {
        $invoice = $this->approvedInvoice();

        $this->assertSame(self::EMAIL_TEMPLATE, $invoice->email_template_view_snapshot);

        $rendered = (new ClientInvoiceMail($invoice))->render();

        $this->assertStringContainsString('Invoice '.$invoice->invoice_number, $rendered);
        $this->assertStringContainsString('Island Motorsports', $rendered);
        $this->assertStringContainsString('View invoice online', $rendered);
        // The kit's ground and border, so the mail reads as the same document.
        $this->assertStringContainsString('background:#F3F4F6', $rendered);
        $this->assertStringContainsString('border:1px solid #E5E7EB', $rendered);
    }

    public function test_the_mail_fetches_no_webfont(): void
    {
        // Mail clients block remote font fetches harder than images, and a
        // half-loaded face is worse than the system stack the kit falls to.
        $rendered = (new ClientInvoiceMail($this->approvedInvoice()))->render();

        $this->assertStringNotContainsString('fonts.googleapis.com', $rendered);
        $this->assertStringNotContainsString('@font-face', $rendered);
        $this->assertStringContainsString('Inter,-apple-system', $rendered);
    }

    public function test_the_text_part_is_the_companion_of_the_chosen_template(): void
    {
        // Previously hardcoded to the default's text body, which would have
        // sent Tracker Pull's HTML beside Kyle Ferguson's plain text.
        $mail = new ClientInvoiceMail($this->approvedInvoice());

        $this->assertSame(self::EMAIL_TEMPLATE, $mail->content()->view);
        $this->assertSame(self::EMAIL_TEMPLATE.'-text', $mail->content()->text);
    }

    public function test_a_retired_email_template_falls_back_as_a_matched_pair(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['email_template_view_snapshot' => 'emails.invoices.retired'])->save();

        $mail = new ClientInvoiceMail($invoice->fresh());

        $this->assertSame('emails.invoices.default', $mail->content()->view);
        $this->assertSame('emails.invoices.default-text', $mail->content()->text);
    }

    public function test_a_template_shipped_without_a_companion_falls_back_for_text_only(): void
    {
        // A registered view with no `-text` sibling: the HTML choice is
        // honoured, and only the plain-text part falls back.
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kf-lonely-views';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory.DIRECTORY_SEPARATOR.'lonely.blade.php', 'Invoice {{ $invoice->invoice_number }}');
        View::addNamespace('lonely', $directory);

        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['email_template_view_snapshot' => 'lonely::lonely'])->save();

        $mail = new ClientInvoiceMail($invoice->fresh());

        $this->assertSame('lonely::lonely', $mail->content()->view);
        $this->assertSame('emails.invoices.default-text', $mail->content()->text);

        unlink($directory.DIRECTORY_SEPARATOR.'lonely.blade.php');
    }

    public function test_the_mail_is_branded_from_the_invoices_snapshot(): void
    {
        $this->business->update(['brand_primary_color' => '#d22720']);

        $invoice = $this->approvedInvoice();
        $this->business->update(['name' => 'Renamed Since']);

        $rendered = (new ClientInvoiceMail($invoice->fresh()))->render();

        $this->assertStringContainsString('#d22720', $rendered);
        $this->assertStringContainsString('Tracker Pull', $rendered);
        $this->assertStringNotContainsString('Renamed Since', $rendered);
    }

    public function test_the_greeting_names_the_contact_without_leaking_a_directive(): void
    {
        // `here@else` is not a directive Blade will compile — a word character
        // butting against the `@` stops it being recognised — so it went out to
        // clients verbatim. Every mail template is checked, not just this
        // checkpoint's, because all four carried the same construct.
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['client_snapshot' => [
            'name' => 'Island Motorsports Association',
            'contact_name' => 'Dana Wheeler',
        ]])->save();

        foreach (self::MAIL_TEMPLATES as $template) {
            $rendered = view($template, $this->mailData($invoice->fresh()))->render();

            $this->assertStringContainsString('Dana, here is', $rendered, $template);
            $this->assertStringNotContainsString('@else', $rendered, $template);
            $this->assertStringNotContainsString('@endif', $rendered, $template);
        }
    }

    public function test_the_greeting_stands_alone_when_there_is_no_contact_name(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['client_snapshot' => ['name' => 'Island Motorsports Association']])->save();

        foreach (self::MAIL_TEMPLATES as $template) {
            $rendered = view($template, $this->mailData($invoice->fresh()))->render();

            $this->assertStringContainsString('Here is', $rendered, $template);
            $this->assertStringNotContainsString('@else', $rendered, $template);
        }
    }

    public function test_the_text_part_carries_what_the_html_does(): void
    {
        $invoice = $this->approvedInvoice();
        $invoice->forceFill(['late_fee_terms_snapshot' => '2% monthly on overdue balances.'])->save();

        $text = view(self::EMAIL_TEMPLATE.'-text', [
            'invoice' => $invoice->fresh(),
            'businessName' => 'Tracker Pull',
            'contactEmail' => 'kyle@trackerpull.ca',
            'clientName' => 'Island Motorsports',
            'periodLabel' => 'August 2026',
            'hostedUrl' => 'https://example.test/i/abc',
        ])->render();

        $this->assertStringContainsString($invoice->invoice_number, $text);
        $this->assertStringContainsString('Island Motorsports', $text);
        $this->assertStringContainsString('https://example.test/i/abc', $text);
        $this->assertStringContainsString('2% monthly on overdue balances.', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_the_sync_command_reports_a_current_kit(): void
    {
        $this->artisan('billing:sync-document-kit --check')
            ->assertExitCode(0)
            ->expectsOutputToContain('tracker-pull');
    }

    public function test_the_sync_command_rejects_an_unknown_kit(): void
    {
        $this->artisan('billing:sync-document-kit nope')
            ->assertExitCode(1)
            ->expectsOutputToContain('Unknown kit');
    }

    /**
     * @return array<string, mixed>
     */
    private function mailData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'businessName' => 'Tracker Pull',
            'contactEmail' => 'kyle@trackerpull.ca',
            'accent' => '#d22720',
            'charcoal' => '#111c26',
            'clientName' => 'Island Motorsports Association',
            'periodLabel' => 'August 2026',
            'hostedUrl' => 'https://example.test/i/abc',
        ];
    }

    /**
     * An issued invoice with figures on it, for the mail to describe.
     */
    private function approvedInvoice(): Invoice
    {
        $invoice = $this->invoice();

        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'subtotal' => '2410.00',
            'tax_total' => '361.50',
            'total' => '2771.50',
            'issued_on' => now()->subDays(2),
            'due_on' => now()->addDays(28),
        ])->save();

        return $invoice->fresh();
    }

    private function render(Invoice $invoice): string
    {
        return app(InvoicePdfRenderer::class)->html($invoice);
    }

    /**
     * An invoice snapshotted while the business used the Tracker Pull template.
     */
    private function invoice(): Invoice
    {
        $business = $this->business->fresh();
        $client = Client::factory()->for($business)->create([
            'name' => 'Island Motorsports',
            'billing_currency' => 'CAD',
        ]);

        $invoice = Invoice::factory()->for($business)->for($client)->create();

        app(InvoiceSnapshotter::class)->capture($invoice);
        $invoice->save();

        return $invoice;
    }

    /**
     * A complete business payload — the endpoint validates the whole form, so
     * a partial update would fail on unrelated required fields.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_entity_id' => $this->business->legal_entity_id,
            'name' => $this->business->name,
            'contact_email' => $this->business->contact_email,
            'notification_email' => 'alerts@trackerpull.ca',
            'invoice_number_prefix' => 'TP-',
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD'],
            'fx_source' => 'bank_of_canada',
            'daily_reminder_time' => '08:00',
            'invoice_template_view' => self::TEMPLATE,
        ], $overrides);
    }
}
