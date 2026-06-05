<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Billing\Invoice;
use Illuminate\View\View;

final class HostedInvoiceController extends Controller
{
    public function show(string $token): View
    {
        $invoice = Invoice::query()
            ->where('hosted_view_token', $token)
            ->with(['business', 'client', 'lines.project', 'payments'])
            ->firstOrFail();

        return view('billing.invoices.hosted', ['invoice' => $invoice]);
    }
}
