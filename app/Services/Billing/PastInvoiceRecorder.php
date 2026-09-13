<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Models\Billing\Invoice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enters a past invoice into the books: recorded as issued on its original
 * date and, when the client has long since paid it, paid in full on the day
 * they did.
 *
 * One step, in one transaction, so a batch of old invoices cannot be left
 * half-entered — issued but showing as owing.
 */
final class PastInvoiceRecorder
{
    public function __construct(
        private readonly InvoiceApprover $approver,
        private readonly PaymentRecorder $payments,
    ) {}

    /**
     * @param  CarbonInterface|null  $paidOn  null when it is still owed
     */
    public function record(
        Invoice $invoice,
        CarbonInterface $issuedOn,
        ?CarbonInterface $dueOn = null,
        ?CarbonInterface $paidOn = null,
        ?PaymentMethod $method = null,
        ?string $reference = null,
        ?User $recordedBy = null,
    ): Invoice {
        return DB::transaction(function () use ($invoice, $issuedOn, $dueOn, $paidOn, $method, $reference, $recordedBy): Invoice {
            $recorded = $this->approver->recordPastIssue($invoice, $issuedOn, $dueOn);

            if ($paidOn !== null) {
                $this->payments->record(
                    $recorded,
                    $recorded->total,
                    $method ?? PaymentMethod::Other,
                    Carbon::parse($paidOn),
                    $reference,
                    null,
                    $recordedBy,
                );
            }

            return $recorded->fresh();
        });
    }
}
