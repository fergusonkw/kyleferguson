<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Models\Billing\Business;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
final class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Inc.',
            'address' => fake()->address(),
            'contact_email' => fake()->companyEmail(),
            'logo_path' => null,
            'brand_primary_color' => '#1e40af',
            'brand_secondary_color' => '#9333ea',
            'invoice_template_view' => 'admin-v2.billing.invoices.templates.default',
            'email_template_view' => 'emails.invoices.default',
            'invoice_number_prefix' => 'INV-',
            'invoice_number_sequence' => 1,
            'default_currency' => 'CAD',
            'supported_currencies' => ['CAD', 'USD'],
            'fx_source' => 'bank_of_canada',
            'tax_registered_from' => null,
            'notification_email' => fake()->companyEmail(),
            'daily_reminder_time' => '08:00:00',
            'late_fee_terms' => null,
        ];
    }

    public function taxRegistered(?DateTimeInterface $from = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_registered_from' => $from ?? now()->startOfYear(),
        ]);
    }
}
