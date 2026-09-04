<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Billing\Invoice;
use App\Services\Billing\InvoicePdfRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client-facing invoice, reached by an unguessable token in the emailed
 * link. No login: asking a client to create an account to read an invoice is a
 * good way not to get paid.
 *
 * Only issued invoices resolve. A draft or a voided invoice returns 404 rather
 * than a "not available" page, so a leaked link cannot even confirm that an
 * invoice exists for that token.
 */
final class HostedInvoiceController extends Controller
{
    public function __construct(private readonly InvoicePdfRenderer $pdf) {}

    public function show(string $token): Response
    {
        $invoice = $this->resolve($token);

        // The same render that produces the PDF, so the page a client reads
        // and the document they download cannot disagree.
        return response($this->pdf->html($invoice, [
            'banner' => $this->paymentBanner($invoice),
            'downloadUrl' => route('invoices.hosted.pdf', $token),
        ]));
    }

    public function pdf(string $token): StreamedResponse
    {
        $invoice = $this->resolve($token);
        $contents = $this->pdf->contents($invoice);

        return response()->streamDownload(
            fn () => print ($contents),
            $this->pdf->downloadFilename($invoice),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function resolve(string $token): Invoice
    {
        $invoice = Invoice::query()
            ->where('hosted_view_token', $token)
            ->with(['topLevelLines.children', 'payments', 'business', 'client'])
            ->firstOrFail();

        abort_unless($invoice->status->isIssued(), 404);

        return $invoice;
    }

    /**
     * What the client should be told about where their payment stands.
     *
     * @return array{tone: string, message: string}|null
     */
    private function paymentBanner(Invoice $invoice): ?array
    {
        $paid = $invoice->amountPaid();

        if (bccomp($paid, '0.00', 2) !== 1) {
            return $invoice->due_on !== null && $invoice->due_on->isPast()
                ? ['tone' => 'overdue', 'message' => 'This invoice was due on '.$invoice->due_on->format('F j, Y').'.']
                : null;
        }

        /** @var Carbon|null $lastPayment */
        $lastPayment = $invoice->payments->max('received_at');

        if (bccomp($paid, $invoice->total, 2) >= 0) {
            return [
                'tone' => 'paid',
                'message' => 'Paid in full'.($lastPayment !== null ? ' on '.$lastPayment->format('F j, Y') : '').'. Thank you.',
            ];
        }

        return [
            'tone' => 'partial',
            'message' => sprintf(
                'Partial payment of $%s received%s. Balance due: $%s %s.',
                number_format((float) $paid, 2),
                $lastPayment !== null ? ' on '.$lastPayment->format('F j, Y') : '',
                number_format((float) $invoice->balanceDue(), 2),
                $invoice->issue_currency,
            ),
        ];
    }
}
