<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Mail\Billing\ClientInvoiceMail;
use App\Models\Billing\Invoice;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class InvoiceApprover
{
    public function __construct(private readonly InvoicePdfRenderer $pdfRenderer) {}

    public function approve(Invoice $invoice): void
    {
        $this->assertTransition($invoice, InvoiceStatus::Approved);

        $invoice->update([
            'status' => InvoiceStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function send(Invoice $invoice): void
    {
        $this->assertTransition($invoice, InvoiceStatus::Sent);

        $pdfPath = $this->pdfRenderer->render($invoice);

        $invoice->update([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
            'pdf_path' => $pdfPath,
        ]);

        Mail::to($invoice->client->contact_email)
            ->send(new ClientInvoiceMail($invoice));
    }

    public function void(Invoice $invoice): void
    {
        $this->assertTransition($invoice, InvoiceStatus::Void);

        $invoice->update([
            'status' => InvoiceStatus::Void,
            'voided_at' => now(),
        ]);
    }

    private function assertTransition(Invoice $invoice, InvoiceStatus $next): void
    {
        if (! $invoice->status->canTransitionTo($next)) {
            throw new RuntimeException(
                "Cannot transition invoice {$invoice->id} from {$invoice->status->value} to {$next->value}."
            );
        }
    }
}
