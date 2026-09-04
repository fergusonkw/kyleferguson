<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;

/**
 * Freezes the business and client details an invoice renders from.
 *
 * An issued invoice must keep rendering as it was issued, so it carries its
 * own copy of everything rather than reading live records. The moment that
 * copy is taken matters: a draft is still a working document, so it re-takes
 * the snapshot whenever it is rebuilt, and again at approval — the point where
 * it becomes a permanent record. Configuring a business between generating a
 * draft and approving it would otherwise issue an invoice with stale details.
 */
final class InvoiceSnapshotter
{
    /**
     * Re-take the invoice's snapshots from the current business and client.
     * Does not save; the caller decides when.
     */
    public function capture(Invoice $invoice): void
    {
        $business = $invoice->business;
        $client = $invoice->client;

        $invoice->fill([
            'template_view_snapshot' => $business->invoice_template_view,
            'email_template_view_snapshot' => $business->email_template_view,
            'late_fee_terms_snapshot' => $business->late_fee_terms,
            'business_snapshot' => $this->business($business),
            'client_snapshot' => $this->client($client),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function business(Business $business): array
    {
        return [
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'address' => $business->address,
            'contact_email' => $business->contact_email,
            'cheque_payable_to' => $business->cheque_payable_to,
            'logo_path' => $business->logo_path,
            'brand_primary_color' => $business->brand_primary_color,
            'brand_secondary_color' => $business->brand_secondary_color,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function client(Client $client): array
    {
        return [
            'name' => $client->name,
            'contact_name' => $client->contact_name,
            'contact_email' => $client->contact_email,
            'billing_address' => $client->billing_address,
            'billing_currency' => $client->billing_currency,
        ];
    }
}
