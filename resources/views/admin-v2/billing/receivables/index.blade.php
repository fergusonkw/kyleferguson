@extends('admin-v2.layouts.vertical', ['title' => 'Receivables'])

@section('content')
    <x-admin-v2.page-title
        title="Receivables"
        :breadcrumbs="[
            ['label' => 'Billing', 'url' => route('admin.billing.index')],
            ['label' => 'Receivables', 'active' => true],
        ]"
    />

    @if($currentBusiness === null)
        <x-admin-v2.card>
            <div class="text-center py-10">
                <i data-lucide="hand-coins" class="size-12 mx-auto text-default-400 mb-3"></i>
                <h4 class="text-lg font-semibold">No businesses configured yet</h4>
                <p class="text-sm text-default-500 mt-1">
                    Create a business and issue an invoice before there is anything to collect.
                </p>
            </div>
        </x-admin-v2.card>
    @else
        <x-admin-v2.billing.receivable-cards :summary="$summary" />

        @if($summary->missingDueDateCount > 0)
            <x-admin-v2.alert
                type="warning"
                message="{{ $summary->missingDueDateCount }} issued invoice(s) have no due date, so nothing can tell whether they are late. Set one from the invoice page."
            />
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mt-5">
            <x-admin-v2.card title="Ageing" class="xl:col-span-1">
                <p class="text-sm text-default-500 mb-4">
                    Outstanding balances by how far past due they are.
                </p>

                @php($agingPeak = max(collect($aging)->max('count'), 1))
                <div class="flex flex-col gap-3">
                    @foreach($aging as $bucket)
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="{{ $bucket['count'] > 0 && $bucket['key'] !== 'not_due' ? 'font-medium' : 'text-default-500' }}">
                                    {{ $bucket['label'] }}
                                </span>
                                <span class="text-default-400">
                                    @if($bucket['totals']->isEmpty())
                                        —
                                    @else
                                        {{ $bucket['totals']->headline($summary->currency) }}
                                    @endif
                                </span>
                            </div>
                            <div class="h-2 rounded bg-default-100 overflow-hidden">
                                <div class="h-full rounded {{ match($bucket['key']) {
                                        'not_due' => 'bg-success',
                                        'no_due_date' => 'bg-default-400',
                                        '1_30' => 'bg-warning',
                                        default => 'bg-danger',
                                    } }}"
                                     style="width: {{ $bucket['count'] === 0 ? 0 : max(6, (int) round(($bucket['count'] / $agingPeak) * 100)) }}%"></div>
                            </div>
                            @if($remainder = $bucket['totals']->remainderLabel($summary->currency))
                                <p class="text-xs text-default-400 mt-1">{{ $remainder }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-admin-v2.card>

            <x-admin-v2.card title="Outstanding invoices" class="xl:col-span-2">
                <x-slot:headerActions>
                    <a href="{{ route('admin.billing.invoices.index') }}" class="text-sm text-primary">All invoices →</a>
                </x-slot:headerActions>

                @if($outstanding->isEmpty())
                    <div class="text-center py-10">
                        <i data-lucide="check-check" class="size-10 mx-auto text-success mb-3"></i>
                        <h5 class="font-semibold">Nothing outstanding</h5>
                        <p class="text-sm text-default-500 mt-1">Every issued invoice has been paid in full.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-default-400 border-b border-default-200">
                                    <th class="pb-2 font-medium">Invoice</th>
                                    <th class="pb-2 font-medium">Client</th>
                                    <th class="pb-2 font-medium">Due</th>
                                    <th class="pb-2 font-medium text-right">Total</th>
                                    <th class="pb-2 font-medium text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($outstanding as $invoice)
                                    @php($late = $reporter->daysOverdue($invoice))
                                    <tr class="border-b border-default-100 last:border-0">
                                        <td class="py-2.5">
                                            <a href="{{ route('admin.billing.invoices.show', $invoice) }}"
                                               class="text-primary font-medium">{{ $invoice->invoice_number }}</a>
                                        </td>
                                        <td class="py-2.5">{{ $invoice->client->name }}</td>
                                        <td class="py-2.5">
                                            @if($invoice->due_on === null)
                                                <span class="badge bg-warning">No due date</span>
                                            @elseif($late !== null)
                                                <span class="text-danger font-medium">{{ $invoice->due_on->format('d M Y') }}</span>
                                                <span class="block text-xs text-danger">{{ $late }} day{{ $late === 1 ? '' : 's' }} late</span>
                                            @else
                                                {{ $invoice->due_on->format('d M Y') }}
                                            @endif
                                        </td>
                                        <td class="py-2.5 text-right text-default-500">
                                            ${{ number_format((float) $invoice->total, 2) }}
                                        </td>
                                        <td class="py-2.5 text-right font-semibold">
                                            ${{ number_format((float) $invoice->balanceDue(), 2) }}
                                            <span class="text-xs font-normal text-default-400">{{ $invoice->issue_currency }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin-v2.card>
        </div>

        <x-admin-v2.card title="Recent payments" class="mt-5">
            <p class="text-sm text-default-500 mb-4">
                Every payment across all invoices, newest first. Voided payments are excluded.
            </p>

            @if($payments->isEmpty())
                <div class="text-center py-10">
                    <i data-lucide="receipt" class="size-10 mx-auto text-default-400 mb-3"></i>
                    <h5 class="font-semibold">No payments recorded yet</h5>
                    <p class="text-sm text-default-500 mt-1">
                        Record one from an invoice to start tracking what has been collected.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-default-400 border-b border-default-200">
                                <th class="pb-2 font-medium">Received</th>
                                <th class="pb-2 font-medium">Invoice</th>
                                <th class="pb-2 font-medium">Client</th>
                                <th class="pb-2 font-medium">Method</th>
                                <th class="pb-2 font-medium">Reference</th>
                                <th class="pb-2 font-medium">Recorded by</th>
                                <th class="pb-2 font-medium text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($payments as $payment)
                                <tr class="border-b border-default-100 last:border-0">
                                    <td class="py-2.5">{{ $payment->received_at->format('d M Y') }}</td>
                                    <td class="py-2.5">
                                        <a href="{{ route('admin.billing.invoices.show', $payment->invoice) }}"
                                           class="text-primary">{{ $payment->invoice->invoice_number }}</a>
                                    </td>
                                    <td class="py-2.5">{{ $payment->invoice->client->name }}</td>
                                    <td class="py-2.5">{{ $payment->method->label() }}</td>
                                    <td class="py-2.5 text-default-500">{{ $payment->reference ?: '—' }}</td>
                                    <td class="py-2.5 text-default-500">{{ $payment->recordedBy?->name ?? '—' }}</td>
                                    <td class="py-2.5 text-right font-semibold">
                                        ${{ number_format((float) $payment->amount, 2) }}
                                        <span class="text-xs font-normal text-default-400">{{ $payment->invoice->issue_currency }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin-v2.card>
    @endif
@endsection
