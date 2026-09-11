<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\InvoiceLineType;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceLine;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lines billed by the hour or the unit.
 *
 * "Consulting $1,140" is a figure a client has to take on trust; "12 hrs at
 * $95.00" is one they can check.
 */
final class CheckpointF0MeteredLinesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::factory()->create(['supported_currencies' => ['CAD', 'USD']]);
        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Passthrough,
        ]);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(1.375)->create();
    }

    public function test_an_hourly_line_derives_its_amount(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '12',
                'unit' => 'hrs',
                'unit_rate' => '95.00',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();

        $this->assertSame('1140.00', $line->amount);
        $this->assertSame('12.00', $line->quantity);
        $this->assertSame('hrs', $line->unit);
        $this->assertSame('95.00', $line->unit_rate);
        $this->assertSame('1140.00', $invoice->fresh()->total);
    }

    public function test_a_submitted_amount_does_not_override_the_calculation(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '10',
                'unit_rate' => '100.00',
                'amount' => '5.00',
            ])
            ->assertOk();

        // The quantity and rate are what the client can check, so they win.
        $this->assertSame('1000.00', InvoiceLine::query()->firstOrFail()->amount);
    }

    public function test_a_flat_line_still_works_without_quantity(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Fixed-price build',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '500.00',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();

        $this->assertSame('500.00', $line->amount);
        $this->assertNull($line->quantity);
        $this->assertFalse($line->isMetered());
    }

    public function test_a_quantity_without_a_rate_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_rate']);
    }

    public function test_a_rate_without_a_quantity_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'unit_rate' => '95.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_rate']);
    }

    public function test_neither_amount_nor_quantity_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_a_zero_quantity_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '0',
                'unit_rate' => '95.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_fractional_hours_are_supported(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '1.5',
                'unit' => 'hrs',
                'unit_rate' => '95.00',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();

        $this->assertSame('142.50', $line->amount);
        $this->assertSame('1.5 hrs', $line->quantityLabel());
    }

    public function test_a_whole_quantity_reads_without_trailing_zeros(): void
    {
        $line = new InvoiceLine(['quantity' => '12.00', 'unit' => 'hrs', 'unit_rate' => '95.00']);

        $this->assertSame('12 hrs', $line->quantityLabel());
        $this->assertSame('12 hrs × $95.00', $line->rateNote());
    }

    public function test_a_quantity_without_a_unit_still_reads(): void
    {
        $line = new InvoiceLine(['quantity' => '3.00', 'unit' => null, 'unit_rate' => '20.00']);

        $this->assertSame('3', $line->quantityLabel());
        $this->assertSame('3 × $20.00', $line->rateNote());
    }

    public function test_an_hourly_line_in_another_currency_converts_the_total(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.invoices.lines.store', $invoice), [
                'label' => 'US-billed consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '10',
                'unit' => 'hrs',
                'unit_rate' => '100.00',
                'currency' => 'USD',
            ])
            ->assertOk();

        $line = InvoiceLine::query()->firstOrFail();

        // 10 × 100 = 1000 USD, converted at 1.375 = 1375.00 CAD. The rate the
        // client reads stays the one the work was priced at.
        $this->assertSame('1375.00', $line->amount);
        $this->assertSame('1000.00', $line->source_amount);
        $this->assertSame('100.00', $line->unit_rate);
        $this->assertSame('10 hrs × $100.00', $line->rateNote());
    }

    public function test_the_document_gains_qty_and_rate_columns_when_a_line_uses_them(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'Consulting',
            'quantity' => '12.00',
            'unit' => 'hrs',
            'unit_rate' => '95.00',
            'amount' => '1140.00',
        ]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('>Qty<', $html);
        $this->assertStringContainsString('>Rate<', $html);
        $this->assertStringContainsString('12 hrs', $html);
        $this->assertStringContainsString('$95.00', $html);
        $this->assertStringContainsString('$1,140.00', $html);
    }

    public function test_the_document_stays_two_columns_when_nothing_is_metered(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)
            ->amount(120)->create(['label' => 'Hosting — Acme']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        // Two empty columns on a hosting-only invoice are noise.
        $this->assertStringNotContainsString('>Qty<', $html);
        $this->assertStringNotContainsString('>Rate<', $html);
    }

    public function test_a_mixed_invoice_shows_a_dash_for_flat_lines(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'Consulting', 'quantity' => '12.00', 'unit' => 'hrs',
            'unit_rate' => '95.00', 'amount' => '1140.00',
        ]);
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)
            ->amount(120)->create(['label' => 'Hosting — Acme']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('>Qty<', $html);
        $this->assertStringContainsString('—', $html);
    }

    public function test_the_admin_shows_the_working_and_offers_the_fields(): void
    {
        $invoice = $this->draft();
        InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'Consulting', 'quantity' => '12.00', 'unit' => 'hrs',
            'unit_rate' => '95.00', 'amount' => '1140.00',
        ]);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('12 hrs × $95.00')
            ->assertSee('name="quantity"', false)
            ->assertSee('name="unit_rate"', false);
    }

    public function test_a_line_can_be_corrected_without_retyping_it(): void
    {
        // Deleting and re-entering a line to fix one field is a poor trade,
        // and a blank unit is exactly the sort of thing noticed afterwards.
        $invoice = $this->draft();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'Consulting', 'quantity' => '22.00', 'unit' => null,
            'unit_rate' => '25.00', 'amount' => '550.00',
        ]);

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.lines.update', [$invoice, $line]), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '22',
                'unit' => 'hours',
                'unit_rate' => '25.00',
            ])
            ->assertOk();

        $fresh = $line->fresh();
        $this->assertSame('hours', $fresh->unit);
        $this->assertSame('22 hours × $25.00', $fresh->rateNote());
        $this->assertSame('550.00', $invoice->fresh()->total);
    }

    public function test_editing_a_line_recalculates_the_invoice_total(): void
    {
        $invoice = $this->draft();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)
            ->amount(550)->create(['label' => 'Consulting']);
        $invoice->forceFill(['subtotal' => 550, 'total' => 550])->save();

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.lines.update', [$invoice, $line]), [
                'label' => 'Consulting',
                'line_type' => InvoiceLineType::Manual->value,
                'quantity' => '30',
                'unit' => 'hours',
                'unit_rate' => '25.00',
            ])
            ->assertOk();

        $this->assertSame('750.00', $invoice->fresh()->total);
    }

    public function test_a_derived_line_cannot_be_edited(): void
    {
        $invoice = $this->draft();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Hosting)->amount(120)->create();

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.lines.update', [$invoice, $line]), [
                'label' => 'Tampered',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '1.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('Hosting', mb_substr($line->fresh()->line_type->label(), 0, 7));
    }

    public function test_a_line_cannot_be_edited_once_the_invoice_leaves_draft(): void
    {
        $invoice = $this->draft();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->amount(100)->create();
        $invoice->forceFill(['status' => \App\Enums\Billing\InvoiceStatus::Sent])->save();

        $this->actingAs($this->createAdmin())
            ->patchJson(route('admin.billing.invoices.lines.update', [$invoice->fresh(), $line]), [
                'label' => 'Changed',
                'line_type' => InvoiceLineType::Manual->value,
                'amount' => '5.00',
            ])
            ->assertForbidden();
    }

    public function test_the_edit_payload_shows_what_was_typed_not_the_converted_figure(): void
    {
        $invoice = $this->draft();
        $line = InvoiceLine::factory()->for($invoice)->ofType(InvoiceLineType::Manual)->create([
            'label' => 'US consulting', 'amount' => '1375.00',
            'source_amount' => '1000.00', 'source_currency' => 'USD', 'fx_rate_applied' => '1.375',
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.invoices.lines.edit', [$invoice, $line]))
            ->assertOk()
            ->assertJsonPath('line.amount', '1000.00')
            ->assertJsonPath('line.currency', 'USD');
    }

    public function test_a_line_from_another_invoice_is_refused(): void
    {
        $invoice = $this->draft();
        $other = Invoice::factory()->for($this->business)->for($this->client)->forPeriod('2026-07')->create();
        $line = InvoiceLine::factory()->for($other)->ofType(InvoiceLineType::Manual)->create();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.invoices.lines.edit', [$invoice, $line]))
            ->assertNotFound();
    }

    public function test_the_unit_field_suggests_common_units(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="unitOptions"', false)
            ->assertSee('value="hours"', false)
            ->assertSee('value="sessions"', false);
    }

    private function draft(): Invoice
    {
        return Invoice::factory()->for($this->business)->for($this->client)
            ->forPeriod(self::PERIOD)->create(['issue_currency' => 'CAD']);
    }
}
