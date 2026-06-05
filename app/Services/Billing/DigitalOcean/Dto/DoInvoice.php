<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean\Dto;

final readonly class DoInvoice
{
    /**
     * @param  list<DoInvoiceItem>  $items
     */
    public function __construct(
        public string $invoiceUuid,
        public string $period,
        public float $amount,
        public array $items,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $items = array_map(
            fn (array $item): DoInvoiceItem => DoInvoiceItem::fromApiPayload($item),
            $payload['invoice_items'] ?? [],
        );

        return new self(
            invoiceUuid: (string) ($payload['invoice_uuid'] ?? ''),
            period: (string) ($payload['invoice_period'] ?? ''),
            amount: (float) ($payload['amount'] ?? 0),
            items: $items,
        );
    }

    /**
     * @param  array<string, mixed>  $summaryPayload  Entry from the invoices list endpoint
     */
    public static function summaryFromApiPayload(array $summaryPayload): self
    {
        return new self(
            invoiceUuid: (string) ($summaryPayload['invoice_uuid'] ?? ''),
            period: (string) ($summaryPayload['invoice_period'] ?? ''),
            amount: (float) ($summaryPayload['amount'] ?? 0),
            items: [],
        );
    }
}
