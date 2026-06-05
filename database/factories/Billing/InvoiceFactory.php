<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create();
        $periodStart = Carbon::parse('2026-06-01');

        return [
            'business_id' => $business->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->endOfMonth(),
            'status' => InvoiceStatus::Draft,
            'issue_currency' => 'CAD',
            'subtotal' => fake()->randomFloat(2, 50, 2000),
            'total' => fake()->randomFloat(2, 50, 2000),
            'fx_rate_snapshot' => 1.38,
            'fx_rate_source' => 'bank_of_canada',
            'fx_rate_period' => '2026-06',
            'template_view_snapshot' => 'admin-v2.billing.invoices.templates.default',
            'email_template_view_snapshot' => 'emails.invoices.default',
            'late_fee_terms_snapshot' => null,
            'approved_at' => null,
            'sent_at' => null,
            'voided_at' => null,
            'pdf_path' => null,
            'hosted_view_token' => bin2hex(random_bytes(32)),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Draft,
            'approved_at' => null,
            'sent_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Approved,
            'approved_at' => now(),
            'sent_at' => null,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Sent,
            'approved_at' => now()->subHour(),
            'sent_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Paid,
            'approved_at' => now()->subDays(7),
            'sent_at' => now()->subDays(6),
        ]);
    }

    public function forPeriod(string $period): static
    {
        $periodStart = Carbon::parse($period.'-01');

        return $this->state(fn (array $attributes): array => [
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->endOfMonth(),
            'fx_rate_period' => $period,
        ]);
    }
}
