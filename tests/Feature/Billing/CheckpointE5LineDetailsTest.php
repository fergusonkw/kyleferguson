<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Line detail text, cross-currency manual lines, and the cheque payee.
 */
final class CheckpointE5LineDetailsTest extends TestCase
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
            'cheque_payable_to' => 'Kyle Ferguson',
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD', 'USD'],
        ]);
        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Passthrough,
        ]);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(1.375)->create();
    }

    public function test_a_line_can_carry_a_detailed_description(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Platform development — Q3',
                'description' => "Discovery and specification\nData model and migrations\nAdmin build-out and QA",
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '6400.00',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();
        $this->assertStringContainsString('Data model and migrations', $line->description);
        $this->assertSame('6400.00', $invoice->fresh()->total);
    }

    public function test_the_description_reaches_the_client_facing_document(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(6400)->create([
            'label' => 'Platform development — Q3',
            'description' => 'Discovery, data model, admin build-out and QA.',
        ]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Platform development — Q3', $html);
        $this->assertStringContainsString('Discovery, data model, admin build-out and QA.', $html);
    }

    public function test_a_description_is_optional(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '100.00',
            ])
            ->assertOk();

        $this->assertNull(InvoiceLine::query()->firstOrFail()->description);
    }

    public function test_a_usd_line_on_a_cad_invoice_is_converted(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Domain renewal — acme.test',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '18.00',
                'currency' => 'USD',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();

        // 18.00 USD at 1.375 = 24.75 CAD
        $this->assertSame('24.75', $line->amount);
        $this->assertSame('18.00', $line->source_amount);
        $this->assertSame('USD', $line->source_currency);
        $this->assertSame('1.37500000', $line->fx_rate_applied);
        $this->assertSame('24.75', $invoice->fresh()->total);
    }

    public function test_the_conversion_is_explained_on_the_invoice(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'Domain renewal',
            'amount' => '24.75',
            'source_amount' => '18.00',
            'source_currency' => 'USD',
            'fx_rate_applied' => '1.375',
        ]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('USD 18.00 at 1.375', $html);
    }

    public function test_a_line_in_the_invoices_own_currency_records_no_conversion(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '100.00',
                'currency' => 'CAD',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();
        $this->assertNull($line->source_currency);
        $this->assertNull($line->fx_rate_applied);
        $this->assertFalse($line->wasConverted());
        $this->assertNull($line->conversionNote());
    }

    public function test_a_converted_discount_is_still_stored_as_a_reduction(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(100)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'USD goodwill',
                'line_type' => InvoiceLineType::Discount->value,
                'amount' => '10.00',
                'currency' => 'USD',
            ])
            ->assertOk();

        // 10 USD -> 13.75 CAD, applied as a reduction.
        $this->assertSame('86.25', $invoice->fresh()->total);
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Euro thing',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '10.00',
                'currency' => 'EUR',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_a_missing_rate_refuses_the_line_rather_than_guessing(): void
    {
        Http::fake(['*/observations/*' => Http::response(['observations' => []])]);

        $invoice = $this->draft();
        FxRate::query()->delete();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Domain renewal',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '18.00',
                'currency' => 'USD',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, InvoiceLine::count());
    }

    public function test_the_cheque_payee_is_snapshotted_and_rendered(): void
    {
        $invoice = app(InvoiceBuilder::class)->build($this->client, self::PERIOD);

        $this->assertSame('Kyle Ferguson', $invoice->business_snapshot['cheque_payable_to']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());
        $this->assertStringContainsString('Cheque payable to:', $html);
        $this->assertStringContainsString('Kyle Ferguson', $html);
    }

    public function test_no_cheque_line_when_the_business_has_no_payee(): void
    {
        $this->business->update(['cheque_payable_to' => null]);

        $invoice = app(InvoiceBuilder::class)->build($this->client, self::PERIOD);
        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringNotContainsString('Cheque payable to:', $html);
    }

    public function test_the_payee_stays_as_snapshotted_after_the_business_changes(): void
    {
        $invoice = app(InvoiceBuilder::class)->build($this->client, self::PERIOD);
        $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();

        $this->business->update(['cheque_payable_to' => 'Some New Entity Inc.']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('Kyle Ferguson', $html);
        $this->assertStringNotContainsString('Some New Entity Inc.', $html);
    }

    public function test_the_line_form_offers_the_businesses_currencies(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('name="currency"', false)
            ->assertSee('USD')
            ->assertSee('Details');
    }

    private function draft(): Invoice
    {
        return Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod(self::PERIOD)->create(['issue_currency' => 'CAD']);
    }
}
