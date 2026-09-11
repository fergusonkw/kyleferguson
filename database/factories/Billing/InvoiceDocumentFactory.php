<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\InvoiceDocumentReason;
use App\Models\Billing\Invoice;
use App\Models\Billing\InvoiceDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDocument>
 */
final class InvoiceDocumentFactory extends Factory
{
    protected $model = InvoiceDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'reason' => InvoiceDocumentReason::Approved,
            'html' => '<html><body><h1>Invoice as issued</h1></body></html>',
        ];
    }

    public function resent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => InvoiceDocumentReason::Resent,
        ]);
    }
}
