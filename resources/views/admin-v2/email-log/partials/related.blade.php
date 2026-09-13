@if($message->related instanceof \App\Models\Billing\Invoice)
    <a href="{{ route('admin.billing.invoices.show', $message->related) }}" class="text-primary">
        {{ $message->related->invoice_number }}
    </a>
@elseif($message->related_type)
    {{ class_basename($message->related_type) }} #{{ $message->related_id }}
@else
    <span class="text-default-400">—</span>
@endif
