<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean\Dto;

use App\Enums\Billing\BillingCategory;

final readonly class DoInvoiceItem
{
    public function __construct(
        public string $product,
        public BillingCategory $category,
        public ?string $resourceUuid,
        public string $description,
        public float $amount,
        public float $taxAmount,
        public ?string $projectName,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $product = (string) ($payload['product'] ?? '');

        return new self(
            product: $product,
            category: BillingCategory::fromDoProduct($product),
            resourceUuid: isset($payload['resource_uuid']) && $payload['resource_uuid'] !== ''
                ? (string) $payload['resource_uuid']
                : null,
            description: (string) ($payload['description'] ?? ''),
            amount: (float) ($payload['amount'] ?? 0),
            taxAmount: (float) ($payload['tax_amount'] ?? 0),
            projectName: isset($payload['project_name']) && $payload['project_name'] !== ''
                ? (string) $payload['project_name']
                : null,
        );
    }
}
