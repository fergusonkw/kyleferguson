@props([
    'summary',
    'linked' => false,
])

{{--
    The four receivable figures, shared by the receivables page and both
    dashboards so they can never quote different numbers for the same thing.
    `linked` turns the tiles into links, for the dashboards that only summarise.
--}}

@php
    $receivablesUrl = route('admin.billing.receivables.index');
    $invoicesUrl = route('admin.billing.invoices.index');
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
    <x-admin-v2.stat-card
        title="Outstanding"
        icon="hand-coins"
        :primaryStatistic="$summary->outstanding->headline($summary->currency)"
        :animateCounter="false"
        :secondaryTitle="$summary->outstandingCount . ' issued invoice' . ($summary->outstandingCount === 1 ? '' : 's')"
        :secondaryColor="$summary->outstandingCount > 0 ? 'primary' : 'success'"
        :detailStatistic="$summary->outstanding->remainderLabel($summary->currency)"
    />

    <x-admin-v2.stat-card
        title="Overdue"
        icon="alarm-clock"
        :primaryStatistic="$summary->overdue->headline($summary->currency)"
        :animateCounter="false"
        :secondaryTitle="$summary->hasOverdue()
            ? $summary->overdueCount . ' past due · oldest ' . $summary->oldestOverdueDays . ' days'
            : 'Nothing past due'"
        :secondaryColor="$summary->hasOverdue() ? 'danger' : 'success'"
        :detailStatistic="$summary->overdue->remainderLabel($summary->currency)"
    />

    <x-admin-v2.stat-card
        title="Approved, not sent"
        icon="send-horizontal"
        :primaryStatistic="$summary->awaitingSend->headline($summary->currency)"
        :animateCounter="false"
        :secondaryTitle="$summary->awaitingSendCount > 0
            ? $summary->awaitingSendCount . ' waiting to go out'
            : 'Nothing waiting'"
        :secondaryColor="$summary->awaitingSendCount > 0 ? 'warning' : 'success'"
        :detailStatistic="$summary->draftCount > 0 ? $summary->draftCount . ' draft(s)' : null"
    />

    <x-admin-v2.stat-card
        title="Collected"
        icon="banknote-arrow-down"
        :primaryStatistic="$summary->collectedRecently->headline($summary->currency)"
        :animateCounter="false"
        :secondaryTitle="'Last ' . $summary->collectedDays . ' days'"
        secondaryColor="success"
        :detailStatistic="$summary->collectedRecently->remainderLabel($summary->currency)"
    />
</div>

@if($linked)
    <div class="flex flex-wrap gap-4 mt-3">
        <a href="{{ $receivablesUrl }}" class="text-sm text-primary">Receivables detail →</a>
        @if($summary->awaitingSendCount > 0 || $summary->draftCount > 0)
            <a href="{{ $invoicesUrl }}" class="text-sm text-primary">Invoices needing action →</a>
        @endif
    </div>
@endif
