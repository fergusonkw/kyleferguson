<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Services\Billing\InvoicePdfRenderer;
use App\Services\Billing\InvoiceSnapshotter;
use App\Services\Billing\InvoiceTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Branding an operator could configure in the database but not in the app.
 *
 * The schema has carried `logo_path` and the two template columns since the
 * businesses table was created; nothing ever set them. These tests cover the
 * upload and the overrides, and — more importantly — the promise that neither
 * can retroactively change an invoice that has already been issued.
 */
final class CheckpointF2BrandingTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_INVOICE_TEMPLATE = 'admin-v2.billing.invoices.templates.default';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::factory()->create([
            'name' => 'Kyle Ferguson Consulting',
            'supported_currencies' => ['CAD'],
        ]);
    }

    public function test_the_form_offers_the_discovered_templates_and_a_logo_field(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.businesses.index'))
            ->assertOk()
            ->assertSee('name="logo"', false)
            ->assertSee('name="invoice_template_view"', false)
            ->assertSee('name="email_template_view"', false)
            ->assertSee(self::DEFAULT_INVOICE_TEMPLATE, false);
    }

    public function test_a_logo_uploaded_with_a_new_business_is_stored(): void
    {
        $this->actingAs($this->createAdmin())
            ->post(route('admin.billing.businesses.store'), $this->payload([
                'name' => 'Second Business',
                'logo' => UploadedFile::fake()->image('brand.png', 200, 60),
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $business = Business::where('name', 'Second Business')->sole();

        $this->assertNotNull($business->logo_path);
        Storage::disk('local')->assertExists($business->logo_path);
    }

    public function test_replacing_a_logo_leaves_the_old_file_in_place(): void
    {
        // Issued invoices snapshot the path they rendered with. Overwriting the
        // file would silently restyle documents a client already holds.
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'logo' => UploadedFile::fake()->image('first.png'),
            ]))
            ->assertOk();

        $first = $this->business->fresh()->logo_path;

        $this->actingAs($admin)
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'logo' => UploadedFile::fake()->image('second.png'),
            ]))
            ->assertOk();

        $second = $this->business->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_removing_a_logo_clears_the_business_but_keeps_the_file(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'logo' => UploadedFile::fake()->image('brand.png'),
            ]))
            ->assertOk();

        $path = $this->business->fresh()->logo_path;

        $this->actingAs($admin)
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'remove_logo' => '1',
            ]))
            ->assertOk();

        $this->assertNull($this->business->fresh()->logo_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_an_unknown_template_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.businesses.update', $this->business), $this->payload([
                'invoice_template_view' => 'admin-v2.billing.invoices.templates.does-not-exist',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_template_view');
    }

    public function test_a_blank_template_is_rejected_rather_than_stored(): void
    {
        // The column is non-nullable with a default; an empty string would push
        // every render onto the fallback path without saying so.
        $this->actingAs($this->createAdmin())
            ->putJson(route('admin.billing.businesses.update', $this->business), $this->payload([
                'email_template_view' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_template_view');
    }

    public function test_edit_returns_the_current_templates_and_a_logo_preview(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'logo' => UploadedFile::fake()->image('brand.png'),
            ]))
            ->assertOk();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.billing.businesses.edit', $this->business))
            ->assertOk()
            ->assertJsonPath('business.has_logo', true)
            ->assertJsonPath('business.invoice_template_view', self::DEFAULT_INVOICE_TEMPLATE);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $response->json('business.logo_preview'));
    }

    public function test_a_rendered_invoice_inlines_the_logo(): void
    {
        // Inlined, not linked: Browsershot renders from a bare HTML string, so
        // a relative URL would resolve to nothing.
        $invoice = $this->invoiceWithLogo();

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringContainsString('class="brand-logo" src="data:image/png;base64,', $html);
    }

    public function test_the_preview_route_renders_the_logo_too(): void
    {
        $invoice = $this->invoiceWithLogo();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.preview', $invoice))
            ->assertOk()
            ->assertSee('class="brand-logo"', false);
    }

    public function test_a_missing_logo_file_falls_back_to_the_monogram(): void
    {
        // Printing a plainer invoice beats failing to print one.
        $invoice = $this->invoiceWithLogo();

        Storage::disk('local')->delete($this->business->fresh()->logo_path);

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringNotContainsString('<img class="brand-logo"', $html);
        $this->assertStringContainsString('<div class="monogram">', $html);
    }

    public function test_an_invoice_keeps_the_logo_it_was_issued_with(): void
    {
        $invoice = $this->invoiceWithLogo();
        $issuedWith = $invoice->business_snapshot['logo_path'];

        $this->actingAs($this->createAdmin())
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'remove_logo' => '1',
            ]))
            ->assertOk();

        $this->assertNull($this->business->fresh()->logo_path);
        $this->assertSame($issuedWith, $invoice->fresh()->business_snapshot['logo_path']);
        $this->assertStringContainsString(
            '<img class="brand-logo"',
            app(InvoicePdfRenderer::class)->html($invoice->fresh()),
        );
    }

    public function test_a_snapshotted_template_that_has_since_gone_falls_back(): void
    {
        $invoice = $this->invoiceWithLogo();
        $invoice->forceFill(['template_view_snapshot' => 'admin-v2.billing.invoices.templates.retired'])->save();

        $this->assertStringContainsString(
            '<img class="brand-logo"',
            app(InvoicePdfRenderer::class)->html($invoice->fresh()),
        );
    }

    public function test_the_registry_discovers_templates_and_skips_text_companions(): void
    {
        $registry = app(InvoiceTemplateRegistry::class);

        $this->assertArrayHasKey(self::DEFAULT_INVOICE_TEMPLATE, $registry->invoiceTemplates());
        $this->assertArrayHasKey('emails.invoices.default', $registry->emailTemplates());
        $this->assertArrayNotHasKey('emails.invoices.default-text', $registry->emailTemplates());
        $this->assertTrue($registry->invoiceTemplateExists(self::DEFAULT_INVOICE_TEMPLATE));
        $this->assertFalse($registry->invoiceTemplateExists('emails.invoices.default'));
    }

    /**
     * An invoice snapshotted while the business had a logo.
     */
    private function invoiceWithLogo(): Invoice
    {
        $this->actingAs($this->createAdmin())
            ->put(route('admin.billing.businesses.update', $this->business), $this->payload([
                'logo' => UploadedFile::fake()->image('brand.png', 200, 60),
            ]))
            ->assertOk();

        $client = Client::factory()->for($this->business->fresh())->create(['billing_currency' => 'CAD']);
        $invoice = Invoice::factory()->for($this->business->fresh())->for($client)->create();

        app(InvoiceSnapshotter::class)->capture($invoice);
        $invoice->save();

        return $invoice;
    }

    /**
     * A complete business payload — these endpoints validate the whole form,
     * so a partial update would fail on unrelated required fields.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->business->name,
            'contact_email' => 'billing@example.com',
            'notification_email' => 'alerts@example.com',
            'invoice_number_prefix' => 'INV-',
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD'],
            'fx_source' => 'bank_of_canada',
            'daily_reminder_time' => '08:00',
        ], $overrides);
    }
}
