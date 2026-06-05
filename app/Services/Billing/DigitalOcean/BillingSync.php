<?php

declare(strict_types=1);

namespace App\Services\Billing\DigitalOcean;

use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use App\Services\Billing\Contracts\CostProviderAdapter;
use App\Services\Billing\DigitalOcean\Dto\DoInvoice;
use App\Services\Billing\DigitalOcean\Dto\DoInvoiceItem;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BillingSync implements CostProviderAdapter
{
    public function __construct(
        private readonly Client $client,
        private readonly ProjectSync $projectSync,
    ) {}

    public function validateCredentials(CostProvider $provider): bool
    {
        return $this->projectSync->validateCredentials($provider);
    }

    public function syncProjectsAndResources(CostProvider $provider): int
    {
        return $this->projectSync->syncProjectsAndResources($provider);
    }

    /**
     * Pull the DO invoice for the given period, store the raw payload (skipping
     * if the content hash matches a previously stored payload), and derive
     * cost_line_items from it. Returns the number of line items written.
     */
    public function syncBilling(CostProvider $provider, string $period): int
    {
        $invoice = $this->fetchInvoiceForPeriod($provider, $period);

        if ($invoice === null) {
            return 0;
        }

        $rawPayload = $invoice->items !== []
            ? ['invoice_uuid' => $invoice->invoiceUuid, 'invoice_period' => $invoice->period, 'amount' => $invoice->amount, 'invoice_items' => array_map(
                fn (DoInvoiceItem $item): array => [
                    'product' => $item->product,
                    'resource_uuid' => $item->resourceUuid,
                    'description' => $item->description,
                    'amount' => $item->amount,
                    'tax_amount' => $item->taxAmount,
                    'project_name' => $item->projectName,
                ],
                $invoice->items,
            )]
            : ['invoice_uuid' => $invoice->invoiceUuid, 'invoice_period' => $invoice->period, 'amount' => $invoice->amount, 'invoice_items' => []];

        $contentHash = hash('sha256', (string) json_encode($rawPayload));

        $existingPayload = ProviderBillingPayload::query()
            ->where('cost_provider_id', $provider->id)
            ->where('period', $period)
            ->where('content_hash', $contentHash)
            ->first();

        if ($existingPayload !== null) {
            return 0;
        }

        $linesWritten = 0;

        DB::transaction(function () use ($provider, $period, $rawPayload, $contentHash, $invoice, &$linesWritten): void {
            /** @var ProviderBillingPayload $billingPayload */
            $billingPayload = ProviderBillingPayload::create([
                'cost_provider_id' => $provider->id,
                'period' => $period,
                'raw_payload' => $rawPayload,
                'content_hash' => $contentHash,
                'fetched_at' => now(),
            ]);

            $resourceMap = $this->buildResourceMap($provider);
            $derivedAt = now();

            foreach ($invoice->items as $item) {
                $providerResourceId = $item->resourceUuid !== null
                    ? ($resourceMap[$item->resourceUuid] ?? null)
                    : null;

                CostLineItem::create([
                    'cost_provider_id' => $provider->id,
                    'provider_resource_id' => $providerResourceId,
                    'project_id' => null,
                    'source_payload_id' => $billingPayload->id,
                    'period' => $period,
                    'usd_amount' => $item->amount,
                    'usd_tax' => $item->taxAmount,
                    'category' => $item->category,
                    'description' => $item->description ?: null,
                    'derived_at' => $derivedAt,
                ]);

                $linesWritten++;
            }
        });

        return $linesWritten;
    }

    /**
     * Find the DO invoice that corresponds to the requested billing period.
     * Returns null if no matching invoice is found.
     */
    private function fetchInvoiceForPeriod(CostProvider $provider, string $period): ?DoInvoice
    {
        try {
            $provider->markSyncRunning();

            foreach ($this->client->listInvoices($provider) as $summary) {
                if ($summary->period === $period) {
                    $invoice = $this->client->getInvoice($provider, $summary->invoiceUuid);
                    $provider->markSyncSucceeded();

                    return $invoice;
                }
            }

            $provider->markSyncSucceeded();

            return null;
        } catch (Throwable $e) {
            $provider->markSyncFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Build a map of resource_uuid → provider_resources.id for all resources
     * belonging to this cost provider.
     *
     * @return array<string, int>
     */
    private function buildResourceMap(CostProvider $provider): array
    {
        return ProviderResource::query()
            ->where('cost_provider_id', $provider->id)
            ->pluck('id', 'provider_resource_id')
            ->all();
    }
}
