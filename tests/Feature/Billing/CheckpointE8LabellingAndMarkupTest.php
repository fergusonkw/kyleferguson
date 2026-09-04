<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\CostCategory;
use App\Enums\Billing\CostProviderSlug;
use App\Enums\Billing\MarkupType;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\FxRate;
use App\Models\Billing\Invoice;
use App\Models\Billing\Project;
use App\Services\Billing\InvoiceBuilder;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Naming provider costs on an invoice, and showing the operator the markup
 * that the client deliberately cannot see.
 */
final class CheckpointE8LabellingAndMarkupTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private Business $business;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::factory()->create();
        $this->client = Client::factory()->for($this->business)->create([
            'billing_currency' => 'CAD',
            'default_markup_type' => MarkupType::Percent,
            'default_markup_value' => 10,
            'default_markup_fee' => 0,
        ]);
        $this->project = Project::factory()->for($this->client)->create(['name' => 'Platform Rebuild']);

        FxRate::factory()->pair('USD', 'CAD')->forPeriod(self::PERIOD)->withRate(1.0)->create();
    }

    public function test_each_provider_has_a_sensible_default_invoice_label(): void
    {
        $this->assertSame('Hosting', CostProviderSlug::DigitalOcean->defaultInvoiceLabel());
        $this->assertSame('Email delivery', CostProviderSlug::Smtp2go->defaultInvoiceLabel());
    }

    public function test_the_invoice_label_falls_back_to_the_providers_default(): void
    {
        $provider = CostProvider::factory()->for($this->business)->smtp2go()->create([
            'display_name' => 'SMTP2Go — Acme account',
            'invoice_label' => null,
        ]);

        // display_name is the operator's bookkeeping name, not the client's.
        $this->assertSame('Email delivery', $provider->invoiceLabel());
    }

    public function test_an_operator_set_label_wins(): void
    {
        $provider = CostProvider::factory()->for($this->business)->smtp2go()
            ->create(['invoice_label' => 'Transactional email']);

        $this->assertSame('Transactional email', $provider->invoiceLabel());
    }

    public function test_an_email_cost_is_not_billed_as_hosting(): void
    {
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);

        $line = $this->build()->topLevelLines()->firstOrFail();

        $this->assertSame('Email delivery — Platform Rebuild', $line->label);
        $this->assertStringNotContainsString('Hosting', $line->label);
    }

    public function test_a_hosting_cost_is_still_called_hosting(): void
    {
        $this->cost($this->digitalOcean(), 100.00, CostCategory::Compute);

        $this->assertSame('Hosting — Platform Rebuild', $this->build()->topLevelLines()->firstOrFail()->label);
    }

    public function test_a_project_drawing_on_two_services_names_both(): void
    {
        $this->cost($this->digitalOcean(), 100.00, CostCategory::Compute);
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);

        $line = $this->build()->topLevelLines()->firstOrFail();

        $this->assertSame('Email delivery & Hosting — Platform Rebuild', $line->label);
    }

    public function test_the_providers_description_carries_onto_the_line(): void
    {
        $provider = $this->smtp2go();
        $provider->update(['invoice_description' => 'Transactional email delivery and deliverability monitoring.']);
        $this->cost($provider, 30.00, CostCategory::Email);

        $invoice = $this->build();

        $this->assertSame(
            'Transactional email delivery and deliverability monitoring.',
            $invoice->topLevelLines()->firstOrFail()->description,
        );
        $this->assertStringContainsString(
            'deliverability monitoring',
            app(InvoicePdfRenderer::class)->html($invoice),
        );
    }

    public function test_sub_items_name_the_service_when_several_contribute(): void
    {
        $this->cost($this->digitalOcean(), 100.00, CostCategory::Compute);
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);

        $children = $this->build()->topLevelLines()->firstOrFail()->children;

        $this->assertEqualsCanonicalizing(
            ['Hosting · Compute', 'Email delivery · Email'],
            $children->pluck('label')->all(),
        );
    }

    public function test_sub_items_stay_plain_when_only_one_service_contributes(): void
    {
        $provider = $this->digitalOcean();
        $this->cost($provider, 60.00, CostCategory::Compute);
        $this->cost($provider, 40.00, CostCategory::Database);

        $children = $this->build()->topLevelLines()->firstOrFail()->children;

        $this->assertEqualsCanonicalizing(['Compute', 'Database'], $children->pluck('label')->all());
    }

    public function test_markup_is_still_charged_once_for_a_project_using_two_services(): void
    {
        // A flat fee split across providers would be charged once each, so the
        // grouping must stay one line per project.
        $this->project->update([
            'markup_type' => MarkupType::FixedFee,
            'markup_value' => 0,
            'markup_fee' => 40,
        ]);

        $this->cost($this->digitalOcean(), 100.00, CostCategory::Compute);
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);

        $invoice = $this->build();

        $this->assertCount(1, $invoice->topLevelLines);
        $this->assertSame('170.00', $invoice->total);
    }

    public function test_the_line_records_the_markup_that_produced_it(): void
    {
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);

        $meta = $this->build()->topLevelLines()->firstOrFail()->metadata;

        $this->assertSame('percent', $meta['markup_type']);
        $this->assertSame('10%', $meta['markup_summary']);
        $this->assertSame('30.00', $meta['cost_in_issue_currency']);
    }

    public function test_the_admin_shows_cost_markup_and_margin(): void
    {
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);
        $invoice = $this->build();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Cost $30.00')
            ->assertSee('10%')
            ->assertSee('$33.00')
            ->assertSee('margin $3.00');
    }

    public function test_the_client_document_never_reveals_the_markup(): void
    {
        $this->cost($this->smtp2go(), 30.00, CostCategory::Email);
        $invoice = $this->build();

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        // The client sees one rolled-up figure, per billing-policy.md. Assert
        // on the figures themselves — "margin" alone appears in the stylesheet.
        $this->assertStringContainsString('$33.00', $html);
        $this->assertStringNotContainsString('margin $', $html);
        $this->assertStringNotContainsString('Cost $30.00', $html);
        $this->assertStringNotContainsString('$30.00', $html);
        $this->assertStringNotContainsString('markup', mb_strtolower($html));
    }

    public function test_the_provider_form_offers_the_labelling_fields(): void
    {
        Business::factory()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.cost-providers.index'))
            ->assertOk()
            ->assertSee('name="invoice_label"', false)
            ->assertSee('name="invoice_description"', false)
            ->assertSee('Never shown to clients');
    }

    public function test_labelling_round_trips_through_the_provider_form(): void
    {
        Http::fake(['*/stats/email_cycle' => Http::response(['data' => ['cycle_used' => 1]])]);
        $client = Client::factory()->for($this->business)->create();

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.billing.cost-providers.store'), [
                'business_id' => $this->business->id,
                'client_id' => $client->id,
                'slug' => CostProviderSlug::Smtp2go->value,
                'display_name' => 'SMTP2Go — internal name',
                'invoice_label' => 'Transactional email',
                'invoice_description' => 'Delivery for application mail.',
                'token' => 'api-'.str_repeat('a', 40),
                'region' => 'global',
                'monthly_fee' => '30.00',
                'fee_currency' => 'USD',
                'enabled' => true,
            ])
            ->assertOk();

        $provider = CostProvider::query()->where('invoice_label', 'Transactional email')->firstOrFail();

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.billing.cost-providers.edit', $provider))
            ->assertOk()
            ->assertJsonPath('provider.invoice_label', 'Transactional email')
            ->assertJsonPath('provider.invoice_description', 'Delivery for application mail.');
    }

    private function build(): Invoice
    {
        return app(InvoiceBuilder::class)->build($this->client, self::PERIOD);
    }

    private function smtp2go(): CostProvider
    {
        return CostProvider::query()->where('slug', CostProviderSlug::Smtp2go)->first()
            ?? CostProvider::factory()->for($this->business)->smtp2go()->create();
    }

    private function digitalOcean(): CostProvider
    {
        return CostProvider::query()->where('slug', CostProviderSlug::DigitalOcean)->first()
            ?? CostProvider::factory()->for($this->business)->create();
    }

    private function cost(CostProvider $provider, float $usd, CostCategory $category): void
    {
        CostLineItem::factory()
            ->for($provider)
            ->forPeriod(self::PERIOD)
            ->ofCategory($category)
            ->usd($usd)
            ->attributedTo($this->project)
            ->create();
    }
}
