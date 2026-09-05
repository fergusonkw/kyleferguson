<?php

declare(strict_types=1);

namespace App\Services\Billing\Smtp2go;

use App\Enums\Billing\ClientStatus;
use App\Enums\Billing\CostCategory;
use App\Enums\Billing\ProjectStatus;
use App\Exceptions\Billing\ProviderConfigurationException;
use App\Models\Billing\CostLineItem;
use App\Models\Billing\CostProvider;
use App\Models\Billing\Project;
use App\Models\Billing\ProviderBillingPayload;
use App\Models\Billing\ProviderResource;
use App\Models\Billing\ResourceAssignment;
use App\Services\Billing\Contracts\BillingSyncAdapter;
use App\Services\Billing\Contracts\CredentialValidator;
use App\Services\Billing\FxRateService;
use App\Services\Billing\Smtp2go\Dto\Smtp2goCycle;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ingests one SMTP2GO account's cost for a period.
 *
 * SMTP2GO exposes usage but no cost, so the charge is the operator-entered
 * flat fee on the provider's `config`; `/stats/email_cycle` is pulled purely
 * as usage evidence. The account is modelled as a single synthetic
 * `subscription` resource so it attributes through the same effective-dated
 * `resource_assignments` path as every other cost.
 */
final class BillingSync implements BillingSyncAdapter, CredentialValidator
{
    private const RESOURCE_ID = 'account';

    private const RESOURCE_TYPE = 'subscription';

    public function __construct(
        private readonly Client $client,
        private readonly FxRateService $fxRates,
    ) {}

    public function validateCredentials(CostProvider $provider): bool
    {
        return $this->client->validateCredentials($provider);
    }

    public function syncBilling(CostProvider $provider, string $period): int
    {
        $provider->markSyncRunning();

        try {
            [$fee, $feeCurrency] = $this->resolveFee($provider);

            $data = $this->client->fetchEmailCycleData($provider);
            $cycle = Smtp2goCycle::fromApiPayload($data);

            $payload = $this->storePayload($provider, $period, $data, $cycle);
            $resource = $this->ensureAccountResource($provider, $cycle);
            $this->seedInitialAssignment($provider, $resource);

            $usdAmount = $this->fxRates->convert($fee, $feeCurrency, 'USD', $period);

            $this->upsertLineItem(
                provider: $provider,
                resource: $resource,
                payload: $payload,
                period: $period,
                cycle: $cycle,
                fee: $fee,
                feeCurrency: $feeCurrency,
                usdAmount: $usdAmount,
            );

            $provider->markSyncSucceeded();

            return 1;
        } catch (Throwable $e) {
            $provider->markSyncFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function resolveFee(CostProvider $provider): array
    {
        $fee = $provider->config('monthly_fee');

        if ($fee === null || $fee === '') {
            throw ProviderConfigurationException::missing($provider, 'monthly_fee');
        }

        if (! is_numeric($fee)) {
            throw ProviderConfigurationException::invalid($provider, 'monthly_fee', 'it is not a number');
        }

        if ((float) $fee < 0) {
            throw ProviderConfigurationException::invalid($provider, 'monthly_fee', 'it is negative');
        }

        $currency = mb_strtoupper((string) $provider->config('fee_currency', 'USD'));

        if (mb_strlen($currency) !== 3) {
            throw ProviderConfigurationException::invalid($provider, 'fee_currency', 'it is not a 3-letter code');
        }

        return [(float) $fee, $currency];
    }

    /**
     * SMTP2GO only ever reports the *current* cycle, so a period keeps one
     * evolving usage row (latest wins) rather than the hash-keyed history a
     * provider with immutable invoices would produce.
     *
     * @param  array<string, mixed>  $data
     */
    private function storePayload(
        CostProvider $provider,
        string $period,
        array $data,
        Smtp2goCycle $cycle,
    ): ProviderBillingPayload {
        return ProviderBillingPayload::query()->updateOrCreate(
            [
                'cost_provider_id' => $provider->id,
                'period' => $period,
                'source_key' => $cycle->cycleStart?->toDateString() ?? $period,
            ],
            [
                'raw_payload' => $data,
                'content_hash' => ProviderBillingPayload::hashPayload($data),
                'fetched_at' => now(),
            ],
        );
    }

    private function ensureAccountResource(CostProvider $provider, Smtp2goCycle $cycle): ProviderResource
    {
        $now = now();

        $resource = ProviderResource::query()->firstOrNew([
            'cost_provider_id' => $provider->id,
            'provider_resource_id' => self::RESOURCE_ID,
            'resource_type' => self::RESOURCE_TYPE,
        ]);

        if (! $resource->exists) {
            $resource->first_seen_at = $now;
        }

        $resource->fill([
            'name' => $provider->display_name,
            'metadata' => $cycle->toMetadata(),
            'last_seen_at' => $now,
        ]);

        $resource->save();

        return $resource;
    }

    /**
     * Attach the account to the linked client's project the first time it is
     * seen. Ambiguity is left for the operator: with no client link, or a
     * client that has anything other than exactly one active project, the
     * resource stays unattributed and surfaces in the reconciliation view.
     */
    private function seedInitialAssignment(CostProvider $provider, ProviderResource $resource): void
    {
        if ($resource->project_id !== null || $provider->client_id === null) {
            return;
        }

        if ($resource->assignments()->exists()) {
            return;
        }

        $projects = Project::query()
            ->where('client_id', $provider->client_id)
            ->where('status', ProjectStatus::Active)
            ->whereHas('client', fn ($q) => $q->where('status', '!=', ClientStatus::Archived))
            ->limit(2)
            ->get();

        if ($projects->count() !== 1) {
            return;
        }

        /** @var Project $project */
        $project = $projects->first();

        DB::transaction(function () use ($resource, $project): void {
            $resource->project_id = $project->id;
            $resource->save();

            ResourceAssignment::create([
                'provider_resource_id' => $resource->id,
                'project_id' => $project->id,
                'provider_project_uuid' => null,
                'observed_from' => now(),
                'observed_to' => null,
            ]);
        });
    }

    private function upsertLineItem(
        CostProvider $provider,
        ProviderResource $resource,
        ProviderBillingPayload $payload,
        string $period,
        Smtp2goCycle $cycle,
        float $fee,
        string $feeCurrency,
        float $usdAmount,
    ): void {
        $lineHash = CostLineItem::makeLineHash([
            'smtp2go',
            self::RESOURCE_TYPE,
            (string) $resource->id,
        ]);

        CostLineItem::query()->updateOrCreate(
            [
                'cost_provider_id' => $provider->id,
                'period' => $period,
                'line_hash' => $lineHash,
            ],
            [
                'provider_resource_id' => $resource->id,
                'source_payload_id' => $payload->id,
                'category' => CostCategory::Email,
                'description' => $this->describe($provider, $cycle),
                'source_amount' => $fee,
                'source_currency' => $feeCurrency,
                'usd_amount' => $usdAmount,
                'usd_tax' => 0,
                'source_reference' => 'smtp2go:subscription',
                'metadata' => $cycle->toMetadata() + [
                    'cycle_covers_period' => $cycle->coversPeriod($period),
                    'over_quota' => $cycle->isOverQuota(),
                ],
                'derived_at' => now(),
            ],
        );
    }

    private function describe(CostProvider $provider, Smtp2goCycle $cycle): string
    {
        $usage = $cycle->max > 0
            ? sprintf(' (%s of %s emails)', number_format($cycle->used), number_format($cycle->max))
            : sprintf(' (%s emails)', number_format($cycle->used));

        return 'SMTP2GO — '.$provider->display_name.$usage;
    }
}
