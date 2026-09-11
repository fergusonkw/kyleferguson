<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Services\Billing\InvoiceFontStore;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoices carry their own fonts.
 *
 * The template matched its HTML original in pulling Archivo and Space Mono
 * from Google Fonts at render time. Browsershot renders from a bare HTML
 * string with no document base and no promise of network access, so the same
 * invoice printed two different ways depending on the host — silently, since a
 * font falling back looks like a design choice rather than a failure.
 */
final class CheckpointF5InvoiceFontsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_declared_face_is_vendored(): void
    {
        // The template asks for four Archivo weights and two Space Mono; a
        // missing file degrades an invoice without failing anything.
        $this->assertTrue(
            app(InvoiceFontStore::class)->isComplete(),
            'Run `php artisan billing:sync-invoice-fonts` to vendor the invoice webfonts.',
        );
    }

    public function test_the_faces_are_emitted_as_data_uris(): void
    {
        $css = app(InvoiceFontStore::class)->faceCss('admin-v2.billing.invoices.templates.default');

        $this->assertStringContainsString("font-family:'Archivo'", $css);
        $this->assertStringContainsString("font-family:'Space Mono'", $css);
        $this->assertStringContainsString('src:url(data:font/woff2;base64,', $css);
        $this->assertSame(6, substr_count($css, '@font-face'));
    }

    public function test_a_template_carries_only_the_families_it_sets_its_text_in(): void
    {
        // Every face is embedded in every document, so shipping all of them
        // would put Inter into invoices set in Archivo and roughly double the
        // size of both templates' PDFs.
        $fonts = app(InvoiceFontStore::class);

        $default = $fonts->faceCss('admin-v2.billing.invoices.templates.default');
        $trackerPull = $fonts->faceCss('admin-v2.billing.invoices.templates.tracker-pull');

        $this->assertStringNotContainsString("font-family:'Inter'", $default);

        $this->assertStringContainsString("font-family:'Inter'", $trackerPull);
        $this->assertStringNotContainsString("font-family:'Archivo'", $trackerPull);
        $this->assertStringNotContainsString("font-family:'Space Mono'", $trackerPull);
        $this->assertSame(4, substr_count($trackerPull, '@font-face'));
    }

    public function test_an_unknown_template_is_given_every_face(): void
    {
        // A heavier document beats one whose fonts silently fall back, so a
        // template nobody has declared families for gets all of them.
        $css = app(InvoiceFontStore::class)->faceCss('admin-v2.billing.invoices.templates.not-declared');

        $this->assertSame(10, substr_count($css, '@font-face'));
    }

    public function test_a_rendered_invoice_embeds_the_fonts_and_fetches_nothing(): void
    {
        $html = app(InvoicePdfRenderer::class)->html($this->invoice());

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString('data:font/woff2;base64,', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_an_invoice_still_renders_when_the_fonts_are_absent(): void
    {
        // A plainer invoice beats no invoice, so the store returns an empty
        // string rather than throwing, and the fallback stacks take over.
        $store = new InvoiceFontStore(sys_get_temp_dir().DIRECTORY_SEPARATOR.'no-fonts-here');

        $this->assertFalse($store->isComplete());
        $this->assertSame('', $store->faceCss());
    }

    public function test_the_sync_command_reports_what_it_vendored(): void
    {
        $this->artisan('billing:sync-invoice-fonts')
            ->assertExitCode(0)
            ->expectsOutputToContain('Vendored 10 font file(s)');
    }

    private function invoice(): Invoice
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();

        return Invoice::factory()->for($business)->for($client)->create();
    }
}
