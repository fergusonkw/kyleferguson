<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Services\Billing\BillingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $period = BillingPeriod::current();

        return [
            'business_id' => Business::factory(),
            'client_id' => Client::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('#####'),
            'period' => $period,
            'period_start' => BillingPeriod::start($period),
            'period_end' => BillingPeriod::end($period),
            'issued_on' => null,
            'due_on' => null,
            'status' => InvoiceStatus::Draft,
            'issue_currency' => 'CAD',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'fx_rate_snapshot' => 1.35,
            'fx_rate_source' => 'bank_of_canada',
            'fx_rate_period' => $period,
            'template_view_snapshot' => 'admin-v2.billing.invoices.templates.default',
            'email_template_view_snapshot' => 'emails.invoices.default',
            'late_fee_terms_snapshot' => null,
            'business_snapshot' => null,
            'client_snapshot' => null,
            'hosted_view_token' => Invoice::generateHostedViewToken(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => $period,
            'period_start' => BillingPeriod::start($period),
            'period_end' => BillingPeriod::end($period),
            'fx_rate_period' => $period,
        ]);
    }

    public function withTotal(float $total): static
    {
        return $this->state(fn (array $attributes): array => [
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'approved_at' => $status === InvoiceStatus::Draft ? null : now(),
            'sent_at' => $status->isIssued() ? now() : null,
            'issued_on' => $status->isIssued() ? now()->toDateString() : null,
            'voided_at' => $status === InvoiceStatus::Void ? now() : null,
        ]);
    }

    public function approved(): static
    {
        return $this->status(InvoiceStatus::Approved);
    }

    public function sent(): static
    {
        return $this->status(InvoiceStatus::Sent);
    }

    public function voided(): static
    {
        return $this->status(InvoiceStatus::Void);
    }
}
