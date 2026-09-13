@extends('admin-v2.layouts.vertical', ['title' => $invoice->invoice_number])

@php
    $money = fn ($v): string => ($v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
    $isDraft = $invoice->status->isEditable();
    $paid = $invoice->amountPaid();
@endphp

@section('content')
    <x-admin-v2.page-title
        :title="$invoice->invoice_number"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Invoices', 'url' => route('admin.billing.invoices.index')],
            ['label' => $invoice->invoice_number, 'active' => true],
        ]"
    />

    <x-admin-v2.card class="mb-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h4 class="text-lg font-semibold mb-0">{{ $invoice->client->name }}</h4>
                    <span class="badge bg-{{ $invoice->status->badgeColor() }}">{{ $invoice->status->label() }}</span>
                    @if($invoice->is_historical)
                        <span class="badge bg-default/15 text-default-500" title="Issued before this system kept the books, and entered for the record">Past invoice</span>
                    @endif
                </div>
                <p class="text-sm text-default-500 mt-1">
                    {{ \App\Services\Billing\BillingPeriod::label($invoice->period) }}
                    · {{ $invoice->period_start->format('M j') }}–{{ $invoice->period_end->format('M j, Y') }}
                    @if($invoice->issued_on)
                        · Issued {{ $invoice->issued_on->format('M j, Y') }}
                    @endif
                    @if($invoice->due_on)
                        · Due {{ $invoice->due_on->format('M j, Y') }}
                    @endif
                </p>

                @can('approve', $invoice)
                    {{-- A past invoice takes its due date when it is recorded. --}}
                    @if($invoice->status !== \App\Enums\Billing\InvoiceStatus::Void && ! ($isDraft && $invoice->is_historical))
                        <div class="flex items-center gap-2 mt-3">
                            <label for="dueOnInput" class="text-xs text-default-400">Due date</label>
                            <input type="date" id="dueOnInput" class="form-input form-input-sm w-40"
                                   value="{{ $invoice->due_on?->toDateString() }}">
                            <button type="button" class="btn btn-sm btn-light" id="saveDueDateBtn">Set</button>
                            <span class="text-xs text-default-400">
                                @if($invoice->due_on === null)
                                    Defaults to {{ $invoice->business->payment_terms_days }} days after approval.
                                @endif
                            </span>
                        </div>
                    @endif
                @endcan
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.billing.invoices.preview', $invoice) }}" target="_blank"
                   class="btn btn-sm btn-light">
                    <i data-lucide="eye" class="size-4 me-1"></i> Preview
                </a>
                <a href="{{ route('admin.billing.invoices.pdf', $invoice) }}" class="btn btn-sm btn-light"
                   title="The invoice as it stands now, including any payments received">
                    <i data-lucide="download" class="size-4 me-1"></i> PDF
                </a>
                @if($invoice->approved_at !== null)
                    <a href="{{ route('admin.billing.invoices.pdf.issued', $invoice) }}" class="btn btn-sm btn-light"
                       title="Exactly what the client was last sent">
                        <i data-lucide="file-text" class="size-4 me-1"></i> As issued
                    </a>
                @endif
                @can('update', $invoice)
                    @unless($invoice->is_historical)
                        <button type="button" class="btn btn-sm btn-light" id="regenerateBtn">
                            <i data-lucide="refresh-cw" class="size-4 me-1"></i> Rebuild
                        </button>
                    @endunless
                @endcan
                @can('approve', $invoice)
                    @if($invoice->status === \App\Enums\Billing\InvoiceStatus::Draft && $invoice->is_historical)
                        <button type="button" class="btn btn-sm btn-primary" id="recordPastBtn">
                            <i data-lucide="history" class="size-4 me-1"></i> Record as Issued
                        </button>
                    @elseif($invoice->status === \App\Enums\Billing\InvoiceStatus::Draft)
                        <button type="button" class="btn btn-sm btn-primary" id="approveBtn">
                            <i data-lucide="circle-check" class="size-4 me-1"></i> Approve
                        </button>
                    @endif
                @endcan
                @can('send', $invoice)
                    @if($invoice->status === \App\Enums\Billing\InvoiceStatus::Approved)
                        <button type="button" class="btn btn-sm btn-primary" id="sentBtn">
                            <i data-lucide="mail" class="size-4 me-1"></i> Mark Sent
                        </button>
                    @elseif($invoice->status->isIssued())
                        <button type="button" class="btn btn-sm btn-light" id="resendBtn">
                            <i data-lucide="mail" class="size-4 me-1"></i> Resend
                        </button>
                    @endif
                @endcan
                @can('void', $invoice)
                    @if($invoice->status !== \App\Enums\Billing\InvoiceStatus::Void)
                        <button type="button" class="btn btn-sm btn-light text-danger" id="voidBtn">
                            <i data-lucide="ban" class="size-4 me-1"></i> Void
                        </button>
                    @elseif($invoice->sent_at === null && $invoice->payments()->withTrashed()->doesntExist())
                        {{-- Only a voided invoice the client never received can go: one
                             that was sent exists outside this system too. --}}
                        <button type="button" class="btn btn-sm btn-light text-danger" id="deleteBtn">
                            <i data-lucide="trash-2" class="size-4 me-1"></i> Delete
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    </x-admin-v2.card>

    @unless($isDraft)
        <x-admin-v2.alert
            type="info"
            message="This invoice has left draft, so its lines are locked. Corrections are made by voiding it and issuing a replacement."
        />
    @endunless

    @if($isDraft && $invoice->is_historical)
        <x-admin-v2.alert
            type="info"
            title="A past invoice, being entered for the record"
            message="Add its lines as they appeared on the original — its costs are never built in. Then record it as issued on the original date, with the payment if it was paid. Nothing is emailed."
        />
    @endif

    @if($invoice->emailMessages->first()?->status->isFailure())
        <x-admin-v2.alert
            type="danger"
            :title="'The last email did not reach '.$invoice->emailMessages->first()->to_address"
            :message="($invoice->emailMessages->first()->error ?? $invoice->emailMessages->first()->status->label()).' — check the address and resend.'"
        />
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
        <div class="xl:col-span-2">
            <x-admin-v2.card title="Lines">
                @can('update', $invoice)
                    <x-slot:headerActions>
                        <button type="button" class="btn btn-sm btn-light" id="addLineBtn">
                            <i data-lucide="plus" class="size-4 me-1"></i> Add Line
                        </button>
                    </x-slot:headerActions>
                @endcan

                <div class="overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center" style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->topLevelLines as $line)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $line->label }}</div>
                                        @if(filled($line->description))
                                            <div class="text-xs text-default-500 mt-1 whitespace-pre-line">{{ $line->description }}</div>
                                        @endif
                                        @if($line->children->isNotEmpty())
                                            <div class="text-xs text-default-400 mt-1">
                                                @foreach($line->children as $child)
                                                    <span class="me-3">{{ $child->label }} {{ $money($child->amount) }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if($line->rateNote())
                                            <div class="text-xs text-default-500 mt-1">{{ $line->rateNote() }}</div>
                                        @endif
                                        @if($line->conversionNote())
                                            <div class="text-xs text-default-400 mt-1">{{ $line->conversionNote() }}</div>
                                        @endif

                                        {{-- Markup is invisible to the client by design, so the
                                             operator needs to see it here or they are approving a
                                             number they cannot check. --}}
                                        @if($line->line_type === \App\Enums\Billing\InvoiceLineType::Hosting && filled($line->metadata['markup_summary'] ?? null))
                                            @php($meta = $line->metadata)
                                            <div class="text-xs mt-1.5 inline-flex flex-wrap items-center gap-1.5">
                                                <span class="text-default-400">Cost {{ $money($meta['cost_in_issue_currency'] ?? 0) }}</span>
                                                <i data-lucide="arrow-right" class="size-3 text-default-400"></i>
                                                <span class="badge bg-info/15 text-info">{{ $meta['markup_summary'] }}</span>
                                                <i data-lucide="arrow-right" class="size-3 text-default-400"></i>
                                                <span class="text-default-500 font-medium">{{ $money($line->amount) }}</span>
                                                @if(($meta['cost_in_issue_currency'] ?? null) !== null)
                                                    <span class="text-default-400">
                                                        (margin {{ $money(bcsub($line->amount, (string) $meta['cost_in_issue_currency'], 2)) }})
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td><span class="badge bg-default">{{ $line->line_type->label() }}</span></td>
                                    <td class="text-end font-medium">{{ $money($line->amount) }}</td>
                                    <td class="text-center">
                                        @if($isDraft && $line->line_type->isOperatorEditable())
                                            @can('update', $invoice)
                                                <div class="flex gap-1 justify-center">
                                                    <button type="button" class="btn btn-sm btn-light edit-line"
                                                            data-id="{{ $line->id }}" aria-label="Edit line" title="Edit line">
                                                        <i data-lucide="pencil" class="size-4"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-light delete-line"
                                                            data-id="{{ $line->id }}" aria-label="Remove line" title="Remove line">
                                                        <i data-lucide="trash-2" class="size-4"></i>
                                                    </button>
                                                </div>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-default-400 py-6">
                                        No lines. Rebuild to pull in this period's costs, or add one by hand.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-end text-default-500">Subtotal</td>
                                <td class="text-end font-medium">{{ $money($invoice->subtotal) }}</td>
                                <td></td>
                            </tr>
                            @if(bccomp($invoice->tax_total, '0.00', 2) === 1)
                                <tr>
                                    <td colspan="2" class="text-end text-default-500">Tax</td>
                                    <td class="text-end font-medium">{{ $money($invoice->tax_total) }}</td>
                                    <td></td>
                                </tr>
                            @endif
                            <tr>
                                <td colspan="2" class="text-end font-semibold">Total</td>
                                <td class="text-end font-semibold">{{ $money($invoice->total) }} {{ $invoice->issue_currency }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-admin-v2.card>

            @include('admin-v2.billing.invoices.partials.client-message')
        </div>

        <div>
            <x-admin-v2.card title="Payments">
                @can('recordPayment', $invoice)
                    @if($invoice->status->isPaymentTracked())
                        <x-slot:headerActions>
                            <button type="button" class="btn btn-sm btn-light" id="addPaymentBtn">
                                <i data-lucide="plus" class="size-4 me-1"></i> Record
                            </button>
                        </x-slot:headerActions>
                    @endif
                @endcan

                <div class="flex items-center justify-between py-2 border-b border-default-200">
                    <span class="text-sm text-default-500">Paid</span>
                    <span class="text-sm font-medium">{{ $money($paid) }}</span>
                </div>
                <div class="flex items-center justify-between py-2 border-b border-default-200">
                    <span class="text-sm text-default-500">Balance due</span>
                    <span class="text-sm font-semibold">{{ $money($invoice->balanceDue()) }}</span>
                </div>
                @if($invoice->isOverpaid())
                    <p class="text-xs text-warning mt-2">
                        Overpaid by {{ $money($invoice->overpaymentAmount()) }} — this carries to their next draft as a credit.
                    </p>
                @endif

                <div class="mt-4">
                    @forelse($invoice->payments as $payment)
                        <div class="flex items-start justify-between py-2 border-b border-default-200 last:border-0">
                            <div>
                                <div class="text-sm font-medium">{{ $money($payment->amount) }}</div>
                                <div class="text-xs text-default-400">
                                    {{ $payment->method->label() }} · {{ $payment->received_at->format('M j, Y') }}
                                    @if($payment->reference) · {{ $payment->reference }} @endif
                                </div>
                            </div>
                            @can('recordPayment', $invoice)
                                <button type="button" class="btn btn-sm btn-light void-payment"
                                        data-id="{{ $payment->id }}" aria-label="Void payment" title="Void payment">
                                    <i data-lucide="undo" class="size-4"></i>
                                </button>
                            @endcan
                        </div>
                    @empty
                        <p class="text-sm text-default-400 py-3 text-center">No payments recorded.</p>
                    @endforelse
                </div>
            </x-admin-v2.card>

            @if($invoice->emailMessages->isNotEmpty())
                @include('admin-v2.billing.invoices.partials.emails')
            @endif

            @if($invoice->status->isIssued())
                {{-- Only an issued invoice resolves at its link, so only an
                     issued one has a link worth showing or replacing. --}}
                <x-admin-v2.card title="Client Link" class="mt-5">
                    <p class="text-xs text-default-400 mb-3">
                        Anyone with this link can view the invoice and download its PDF — no login.
                    </p>
                    <div class="flex gap-2">
                        <input type="text" id="clientLinkInput" readonly
                               class="form-input form-input-sm grow font-mono text-xs"
                               value="{{ route('invoices.hosted.show', $invoice->hosted_view_token) }}"
                               aria-label="Client link">
                        <button type="button" class="btn btn-sm btn-light" id="copyLinkBtn"
                                aria-label="Copy link" title="Copy link">
                            <i data-lucide="copy" class="size-4"></i>
                        </button>
                    </div>
                    @can('rotateLink', $invoice)
                        <div class="flex items-start justify-between gap-3 mt-3">
                            <p class="text-xs text-default-400 mb-0">
                                Sent to the wrong person? Replace it — the old link stops working at once.
                            </p>
                            <button type="button" class="btn btn-sm btn-light text-danger shrink-0" id="rotateLinkBtn">
                                <i data-lucide="rotate-ccw" class="size-4 me-1"></i> Replace
                            </button>
                        </div>
                    @endcan
                </x-admin-v2.card>
            @endif

            <x-admin-v2.card title="Snapshot" class="mt-5">
                <p class="text-xs text-default-400 mb-3">
                    Captured at generation so this invoice stays reproducible.
                </p>
                <div class="flex items-center justify-between py-1 text-sm">
                    <span class="text-default-500">FX rate</span>
                    <span>{{ rtrim(rtrim($invoice->fx_rate_snapshot, '0'), '.') }} USD→{{ $invoice->issue_currency }}</span>
                </div>
                <div class="flex items-center justify-between py-1 text-sm">
                    <span class="text-default-500">Rate source</span>
                    <span>{{ $invoice->fx_rate_source ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between py-1 text-sm">
                    <span class="text-default-500">Template</span>
                    <span class="text-xs">{{ class_basename($invoice->template_view_snapshot) }}</span>
                </div>
            </x-admin-v2.card>
        </div>
    </div>

    @can('update', $invoice)
        <x-admin-v2.offcanvas canvasId="lineOffcanvas" title="Add Line" size="medium">
            <form id="lineForm">
                @csrf
                <input type="hidden" id="lineId" name="line_id">
                <x-admin-v2.form.input name="label" label="Title" :required="true" placeholder="Consulting — August" />
                <x-admin-v2.form.textarea name="description" label="Details" rows="4"
                                          placeholder="What this line covers. Shown to the client under the title — use it to break down a large figure." />
                <x-admin-v2.form.select name="line_type" label="Type" :required="true" :options="$lineTypes" />

                {{-- Work billed by the hour or the unit: the amount is worked
                     out from these, so the client can check the figure. --}}
                <div class="grid grid-cols-3 gap-3">
                    <x-admin-v2.form.input name="quantity" type="number" step="0.01" min="0" label="Quantity" placeholder="12" />
                    {{-- Free text, but with suggestions and a default: left blank
                         a line reads "22 × $25.00", which tells a client less
                         than "22 hours × $25.00". --}}
                    <x-admin-v2.form.input name="unit" label="Unit" placeholder="hours" maxlength="32" list="unitOptions" />
                    <x-admin-v2.form.input name="unit_rate" type="number" step="0.01" label="Rate" placeholder="95.00" />
                </div>
                <datalist id="unitOptions">
                    @foreach(['hours', 'days', 'weeks', 'months', 'sessions', 'units', 'items', 'licences'] as $unit)
                        <option value="{{ $unit }}"></option>
                    @endforeach
                </datalist>

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <x-admin-v2.form.input name="amount" type="number" step="0.01" label="Amount" placeholder="0.00" />
                    </div>
                    <x-admin-v2.form.select name="currency" label="Currency" :options="$lineCurrencies"
                                            :selected="$invoice->issue_currency" />
                </div>
                <p class="text-xs text-default-400 -mt-2 mb-3" id="lineAmountHint">
                    Enter a quantity and rate for hourly or per-unit work and the amount is worked out for
                    you; otherwise enter a flat amount. Discounts and credits go in as positive numbers —
                    they are applied as reductions. Another currency is converted at this period's rate.
                    Hosting and recurring lines are derived: use an adjustment so the original stays on
                    the record.
                </p>
                <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                    <button type="button" class="btn btn-light" data-hs-overlay="#lineOffcanvas">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="lineSubmitBtn">
                        <i data-lucide="check" class="size-4 me-1"></i><span class="btn-text">Save Line</span>
                    </button>
                </div>
            </form>
        </x-admin-v2.offcanvas>
    @endcan

    @can('send', $invoice)
        @if($invoice->status->isIssued())
            {{-- What "give them the full terms" means today, offered as one click
                 rather than left for the operator to count out. Kept to the
                 one-line form deliberately: this view already uses it for the
                 line metadata, and Blade mis-pairs a later block-form opener
                 and closer with that earlier one-liner. --}}
            @php($restartedDueOn = now()->addDays($invoice->business->payment_terms_days))
            <x-admin-v2.offcanvas canvasId="resendOffcanvas" title="Resend Invoice" size="medium">
                <form id="resendForm">
                    @csrf
                    <x-admin-v2.form.input name="recipient" type="email" label="Send to" :required="true"
                                           :value="$invoice->client->contact_email" />
                    <p class="text-xs text-default-400 -mt-3 mb-4">
                        The client's current address. This changes where this one email goes — update the client
                        to change where future invoices go.
                    </p>

                    <x-admin-v2.form.input name="due_on" type="date" label="Due date"
                                           :value="$invoice->due_on?->toDateString()" />
                    <p class="text-xs text-default-400 -mt-3 mb-4">
                        If the first email went astray, the client should not be held to terms that started before
                        they had the invoice.
                        <button type="button" class="text-primary underline" id="restartTermsBtn"
                                data-date="{{ $restartedDueOn->toDateString() }}">
                            Restart the {{ $invoice->business->payment_terms_days }}-day terms
                            ({{ $restartedDueOn->format('M j, Y') }})
                        </button>
                    </p>

                    {{-- Its own id: an invoice paid without ever being emailed shows
                         the message card's box on the same page. --}}
                    <div class="mb-5">
                        <label for="resend_client_message" class="form-label">Message</label>
                        <textarea id="resend_client_message" name="client_message" rows="5"
                                  class="form-textarea">{{ $invoice->client_message }}</textarea>
                        <p class="text-xs text-default-400 mt-1">
                            Goes in this email, under the greeting — keep what the client last received, or change it.
                        </p>
                    </div>

                    <x-admin-v2.form.checkbox name="replace_link"
                        label="Replace the client link"
                        help="Tick this if the last email reached someone who should not have it. Their link stops working; this email carries the new one." />

                    <p class="text-xs text-default-400">
                        The PDF is re-rendered before sending, so it shows the current due date and balance.
                    </p>

                    <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                        <button type="button" class="btn btn-light" data-hs-overlay="#resendOffcanvas">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="resendSubmitBtn">
                            <i data-lucide="mail" class="size-4 me-1"></i><span class="btn-text">Resend</span>
                        </button>
                    </div>
                </form>
            </x-admin-v2.offcanvas>
        @endif
    @endcan

    @can('approve', $invoice)
        @if($isDraft && $invoice->is_historical)
            {{-- Fields carry their own ids: the payment form's are on this page too. --}}
            <x-admin-v2.offcanvas canvasId="recordPastOffcanvas" title="Record as Issued" size="medium">
                <form id="recordPastForm">
                    @csrf
                    <p class="text-sm text-default-500 mb-4">
                        Enters {{ $invoice->invoice_number }} on the dates things really happened. Nothing is
                        emailed — the client has had it all along.
                    </p>

                    <div class="mb-5">
                        <label for="record_issued_on" class="form-label">Issued on <span class="text-danger">*</span></label>
                        <input type="date" id="record_issued_on" name="issued_on" class="form-input" required
                               max="{{ now()->toDateString() }}">
                        <p class="text-xs text-default-400 mt-1">
                            The date on the original. The GST/HST threshold counts the invoice on this date, not today.
                        </p>
                    </div>

                    <div class="mb-5">
                        <label for="record_due_on" class="form-label">Due on</label>
                        <input type="date" id="record_due_on" name="due_on" class="form-input">
                        <p class="text-xs text-default-400 mt-1">
                            Leave empty for {{ $invoice->business->payment_terms_days }} days after the issue date.
                        </p>
                    </div>

                    <div class="flex items-center gap-2 mb-4">
                        <input type="checkbox" id="record_paid" name="paid" value="1" checked
                               class="form-checkbox form-checkbox-light size-4.25">
                        <label for="record_paid" class="text-sm">It was paid in full</label>
                    </div>

                    <div id="recordPastPayment">
                        <div class="mb-5">
                            <label for="record_paid_on" class="form-label">Paid on <span class="text-danger">*</span></label>
                            <input type="date" id="record_paid_on" name="paid_on" class="form-input" required
                                   max="{{ now()->toDateString() }}">
                        </div>
                        <x-admin-v2.form.select name="method" id="record_method" label="Method" :required="true"
                                                :options="$paymentMethods" />
                        <div class="mb-5">
                            <label for="record_reference" class="form-label">Reference</label>
                            <input type="text" id="record_reference" name="reference" class="form-input"
                                   maxlength="255" placeholder="ETR-99120">
                        </div>
                    </div>

                    <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                        <button type="button" class="btn btn-light" data-hs-overlay="#recordPastOffcanvas">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="recordPastSubmitBtn">
                            <i data-lucide="check" class="size-4 me-1"></i><span class="btn-text">Record</span>
                        </button>
                    </div>
                </form>
            </x-admin-v2.offcanvas>
        @endif
    @endcan

    @can('recordPayment', $invoice)
        <x-admin-v2.offcanvas canvasId="paymentOffcanvas" title="Record Payment" size="medium">
            <form id="paymentForm">
                @csrf
                <x-admin-v2.form.input name="amount" type="number" step="0.01" label="Amount" :required="true"
                                       :value="$invoice->balanceDue()" />
                <x-admin-v2.form.select name="method" label="Method" :required="true" :options="$paymentMethods" />
                <x-admin-v2.form.input name="received_at" type="date" label="Received" :required="true"
                                       :value="now()->toDateString()" />
                <x-admin-v2.form.input name="reference" label="Reference" placeholder="ETR-99120" />
                <x-admin-v2.form.textarea name="notes" label="Notes" rows="2" />
                <div class="border-t border-default-200 flex gap-2 justify-end pt-4 mt-4">
                    <button type="button" class="btn btn-light" data-hs-overlay="#paymentOffcanvas">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="paymentSubmitBtn">
                        <i data-lucide="check" class="size-4 me-1"></i><span class="btn-text">Record</span>
                    </button>
                </div>
            </form>
        </x-admin-v2.offcanvas>
    @endcan
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const base = @json(route('admin.billing.invoices.show', $invoice));

    async function post (url, body, method = 'POST') {
        const r = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: body ?? new FormData(),
        });
        return r.json();
    }

    /** Domain refusals come back as 422 with a message worth showing verbatim. */
    function report (data, fallback) {
        if (data.success) {
            Alert.toast(data.message, 'success');
            setTimeout(() => window.location.reload(), 700);
            return;
        }
        const msg = data.errors ? Object.values(data.errors).flat().join('<br>') : (data.message || fallback);
        Alert.html(msg, 'Not allowed');
    }

    document.getElementById('approveBtn')?.addEventListener('click', async () => {
        const ok = await Alert.confirm(
            'The invoice becomes immutable and its PDF is generated. Corrections after this mean voiding and re-issuing.',
            'Approve this invoice?',
            'Approve',
        );
        if (!ok) return;
        report(await post(`${base}/approve`), 'Could not approve.');
    });

    // Whatever is in the message box goes with the email, saved or not.
    document.getElementById('sentBtn')?.addEventListener('click', async () => {
        const body = new FormData();
        const message = document.querySelector('#clientMessageForm [name="client_message"]');
        if (message) body.append('client_message', message.value);
        report(await post(`${base}/sent`, body), 'Could not mark as sent.');
    });

    document.getElementById('saveClientMessageBtn')?.addEventListener('click', async () => {
        const body = new FormData(document.getElementById('clientMessageForm'));
        body.append('_method', 'PATCH');
        report(await post(`${base}/client-message`, body), 'Could not save the message.');
    });

    document.getElementById('recordPastBtn')?.addEventListener('click', () => {
        HSOverlay.open('#recordPastOffcanvas');
    });

    // Unticked, the payment fields are disabled rather than just hidden: a
    // disabled field is neither validated by the browser nor submitted.
    const paidToggle = document.getElementById('record_paid');
    function syncPastPayment () {
        const section = document.getElementById('recordPastPayment');
        if (!paidToggle || !section) return;
        section.classList.toggle('hidden', !paidToggle.checked);
        section.querySelectorAll('input, select').forEach((field) => { field.disabled = !paidToggle.checked; });
    }
    paidToggle?.addEventListener('change', syncPastPayment);
    syncPastPayment();

    document.getElementById('recordPastForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const btn = document.getElementById('recordPastSubmitBtn');
        btn.disabled = true;

        try {
            report(await post(`${base}/record-past`, new FormData(e.target)), 'Could not record the invoice.');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('regenerateBtn')?.addEventListener('click', async () => {
        report(await post(`${base}/regenerate`), 'Could not rebuild.');
    });

    document.getElementById('voidBtn')?.addEventListener('click', async () => {
        const ok = await Alert.confirmDelete(
            'Voiding is permanent. The invoice keeps its number so the sequence stays intact.',
            'Void this invoice?',
        );
        if (!ok) return;
        report(await post(`${base}/void`), 'Could not void.');
    });

    document.getElementById('resendBtn')?.addEventListener('click', () => {
        HSOverlay.open('#resendOffcanvas');
    });

    document.getElementById('restartTermsBtn')?.addEventListener('click', (e) => {
        document.querySelector('#resendForm input[name="due_on"]').value = e.currentTarget.dataset.date;
    });

    document.getElementById('resendForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const btn = document.getElementById('resendSubmitBtn');
        btn.disabled = true;

        try {
            report(await post(`${base}/resend`, new FormData(e.target)), 'Could not resend the invoice.');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('copyLinkBtn')?.addEventListener('click', async () => {
        const input = document.getElementById('clientLinkInput');

        // The Clipboard API only exists in a secure context, and a local
        // `.test` domain over plain HTTP is not one — so fall back to
        // selecting the field and copying the old way.
        try {
            await navigator.clipboard.writeText(input.value);
        } catch {
            input.select();
            document.execCommand('copy');
        }

        Alert.toast('Link copied.', 'success');
    });

    document.getElementById('rotateLinkBtn')?.addEventListener('click', async () => {
        const ok = await Alert.confirm(
            'The current link stops working immediately — including the one in the email the client already has. '
            + 'Resend the invoice to give them the new one.',
            'Replace the client link?',
            'Replace link',
        );
        if (!ok) return;
        report(await post(`${base}/rotate-link`), 'Could not replace the link.');
    });

    document.getElementById('saveDueDateBtn')?.addEventListener('click', async () => {
        const value = document.getElementById('dueOnInput').value;
        if (!value) { Alert.error('Choose a date first.'); return; }

        const body = new FormData();
        body.append('_method', 'PATCH');
        body.append('due_on', value);
        report(await post(`${base}/due-date`, body), 'Could not set the due date.');
    });

    document.getElementById('deleteBtn')?.addEventListener('click', async () => {
        const ok = await Alert.confirmDelete(
            'This voided invoice was never sent, so nothing outside this system references it. '
            + 'Its number stays used so the sequence keeps its meaning.',
            'Delete this invoice?',
        );
        if (!ok) return;

        const r = await fetch(base, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await r.json();

        if (data.success) {
            Alert.toast(data.message, 'success');
            setTimeout(() => { window.location = data.redirect; }, 700);
        } else {
            Alert.html(data.message || 'Could not delete.', 'Not allowed');
        }
    });

    document.getElementById('addLineBtn')?.addEventListener('click', () => {
        document.getElementById('lineForm').reset();
        document.getElementById('lineId').value = '';
        document.getElementById('lineOffcanvasLabel').textContent = 'Add Line';
        delete document.querySelector('input[name="unit"]').dataset.touched;
        syncLineAmount();
        HSOverlay.open('#lineOffcanvas');
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.edit-line');
        if (!btn) return;

        try {
            const r = await fetch(`${base}/lines/${btn.dataset.id}/edit`, { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!data.success) { Alert.error(data.message || 'Could not load the line.'); return; }

            const form = document.getElementById('lineForm');
            form.reset();

            for (const [key, value] of Object.entries(data.line)) {
                const field = form.querySelector(`[name="${key}"]`);
                if (field) field.value = value ?? '';
            }

            document.getElementById('lineId').value = data.line.id;
            document.getElementById('lineOffcanvasLabel').textContent = 'Edit Line';
            // Whatever is loaded is the operator's own value, not a default.
            document.querySelector('input[name="unit"]').dataset.touched = '1';
            syncLineAmount();
            HSOverlay.open('#lineOffcanvas');
        } catch { Alert.error('Could not load the line.'); }
    });

    /**
     * With a quantity and rate, the amount is derived — so show the result and
     * stop it being edited, rather than letting the two disagree.
     */
    function syncLineAmount () {
        const qty = document.querySelector('input[name="quantity"]');
        const rate = document.querySelector('input[name="unit_rate"]');
        const unit = document.querySelector('input[name="unit"]');
        const amount = document.querySelector('input[name="amount"]');
        if (!qty || !rate || !amount) return;

        // Entering a quantity almost always means hours here, and a blank unit
        // silently produces a vaguer line. Fill it in rather than leave the
        // omission to be noticed on the finished invoice; it stays editable.
        if (unit && qty.value !== '' && unit.value === '' && !unit.dataset.touched) {
            unit.value = 'hours';
        }

        const metered = qty.value !== '' && rate.value !== '';
        amount.readOnly = metered;
        amount.classList.toggle('bg-default-100', metered);

        if (metered) {
            amount.value = (parseFloat(qty.value) * parseFloat(rate.value)).toFixed(2);
        }
    }

    // Once the unit is edited by hand, stop filling it in.
    document.querySelector('input[name="unit"]')?.addEventListener('input', function () {
        this.dataset.touched = this.value === '' ? '' : '1';
    });

    ['quantity', 'unit_rate'].forEach((name) => {
        document.querySelector(`input[name="${name}"]`)?.addEventListener('input', syncLineAmount);
    });

    document.getElementById('lineForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = document.getElementById('lineId').value;
        const body = new FormData(e.target);
        if (id) body.append('_method', 'PATCH');

        report(
            await post(id ? `${base}/lines/${id}` : `${base}/lines`, body),
            id ? 'Could not update the line.' : 'Could not add the line.',
        );
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.delete-line');
        if (!btn) return;
        const ok = await Alert.confirmDelete('This line will be removed from the draft.', 'Remove line?');
        if (!ok) return;
        const r = await fetch(`${base}/lines/${btn.dataset.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        report(await r.json(), 'Could not remove the line.');
    });

    document.getElementById('addPaymentBtn')?.addEventListener('click', () => {
        HSOverlay.open('#paymentOffcanvas');
    });

    document.getElementById('paymentForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        report(await post(`${base}/payments`, new FormData(e.target)), 'Could not record the payment.');
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.void-payment');
        if (!btn) return;
        const ok = await Alert.confirmDelete(
            'The payment is reversed but kept on the record, and the invoice status is recalculated.',
            'Void this payment?',
        );
        if (!ok) return;
        const r = await fetch(`${base}/payments/${btn.dataset.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        report(await r.json(), 'Could not void the payment.');
    });
});
</script>
@endpush
