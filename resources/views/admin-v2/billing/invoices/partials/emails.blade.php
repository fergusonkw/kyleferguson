{{-- Every email that carried this invoice, newest first, with what SMTP2Go
     reported for each — so "did the client get it?" has an answer here. --}}
<x-admin-v2.card title="Emails" class="mt-5">
    @foreach($invoice->emailMessages as $email)
        <div class="py-2 border-b border-default-200 last:border-0">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm break-all">{{ $email->to_address }}</span>
                <span class="badge bg-{{ $email->status->badgeColor() }} shrink-0">{{ $email->status->label() }}</span>
            </div>
            <div class="text-xs text-default-400 mt-0.5">
                {{ ($email->sent_at ?? $email->created_at)->format('M j, Y g:i A') }}
                @if($email->firstOpenedAt())
                    · Opened {{ $email->firstOpenedAt()->format('M j, g:i A') }}
                @endif
                @unless($email->wasDelivered())
                    · written to the {{ $email->mailer }} mailer only
                @endunless
                @can('viewAny-email-log')
                    · <a href="{{ route('admin.email-log.show', $email) }}" class="text-primary">Details</a>
                @endcan
            </div>
            @if($email->error)
                <p class="text-xs {{ $email->status->isFailure() ? 'text-danger' : 'text-warning' }} mt-1">{{ $email->error }}</p>
            @endif
        </div>
    @endforeach
</x-admin-v2.card>
