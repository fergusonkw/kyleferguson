@extends('admin-v2.layouts.vertical', ['title' => 'Email #' . $message->id])

@section('content')
<x-admin-v2.page-title
    title="Email Detail"
    :breadcrumbs="[
        ['label' => 'Email Log', 'url' => route('admin.email-log.index')],
        ['label' => '#' . $message->id, 'active' => true],
    ]"
/>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
    <div class="xl:col-span-2 flex flex-col gap-5">
        <x-admin-v2.card :title="$message->subject ?? '(no subject)'">
            <x-slot:headerActions>
                <span class="badge bg-{{ $message->status->badgeColor() }}">{{ $message->status->label() }}</span>
            </x-slot:headerActions>

            <dl class="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
                <dt class="text-default-400">From</dt>
                <dd class="col-span-2 break-all">{{ $message->from_address ?? '—' }}</dd>
                <dt class="text-default-400">To</dt>
                <dd class="col-span-2 break-all">{{ $message->to_address }}</dd>
                <dt class="text-default-400">About</dt>
                <dd class="col-span-2">@include('admin-v2.email-log.partials.related')</dd>
                <dt class="text-default-400">Sent</dt>
                <dd class="col-span-2">{{ $message->sent_at?->format('M j, Y g:i:s A') ?? 'Not accepted by the mailer' }}</dd>
                <dt class="text-default-400">Sent by</dt>
                <dd class="col-span-2">{{ $message->sentBy?->name ?? 'System' }}</dd>
                <dt class="text-default-400">Mailer</dt>
                <dd class="col-span-2">
                    {{ $message->mailer ?? '—' }}
                    @unless($message->wasDelivered())
                        <span class="text-xs text-default-400">— written down, not delivered</span>
                    @endunless
                </dd>
                <dt class="text-default-400">Mailable</dt>
                <dd class="col-span-2 font-mono text-xs break-all">{{ $message->mailable_class ?? '—' }}</dd>
                <dt class="text-default-400">SMTP2Go ID</dt>
                <dd class="col-span-2 font-mono text-xs">{{ $message->provider_message_id ?? '—' }}</dd>
            </dl>
        </x-admin-v2.card>

        @if($message->error)
            <x-admin-v2.alert
                :type="$message->status->isFailure() ? 'danger' : 'warning'"
                :title="$message->status->isFailure() ? 'Why it did not arrive' : 'Why it is delayed'"
                :message="$message->error"
            />
        @endif

        {{-- What the recipient saw. Sandboxed with nothing allowed, so the
             email's own markup can neither run script nor navigate this page. --}}
        <x-admin-v2.card title="Content">
            @if($message->content_withheld)
                <div class="flex items-start gap-2 text-sm text-default-400">
                    <i data-lucide="lock" class="size-4 mt-0.5 shrink-0"></i>
                    <p>This email carried a sign-in or verification link, so its body was not kept.</p>
                </div>
            @elseif($message->html_body)
                <div class="border border-default-200 rounded overflow-hidden">
                    <iframe srcdoc="{{ $message->html_body }}" title="The email as sent" sandbox=""
                            referrerpolicy="no-referrer" class="w-full h-[36rem] bg-white"></iframe>
                </div>
                @if($message->text_body)
                    <details class="text-xs text-default-400 mt-3">
                        <summary class="cursor-pointer">Plain-text version</summary>
                        <pre class="bg-default-100 p-3 rounded mt-2 whitespace-pre-wrap">{{ $message->text_body }}</pre>
                    </details>
                @endif
            @elseif($message->text_body)
                <pre class="bg-default-100 p-3 rounded text-sm whitespace-pre-wrap">{{ $message->text_body }}</pre>
            @else
                <p class="text-sm text-default-400">No body was recorded for this email.</p>
            @endif

            @if($message->attachments)
                <h6 class="text-xs font-semibold text-default-500 uppercase mt-5 mb-2">Attachments</h6>
                <ul class="flex flex-col gap-1 text-sm">
                    @foreach($message->attachments as $attachment)
                        <li class="flex items-center gap-2">
                            <i data-lucide="paperclip" class="size-4 text-default-400"></i>
                            <span>{{ $attachment['filename'] ?? 'unnamed' }}</span>
                            <span class="text-xs text-default-400">{{ number_format($attachment['size'] / 1024, 1) }} KB</span>
                            <span class="text-xs text-default-400 font-mono" title="SHA-256 of the file as sent">{{ substr($attachment['sha256'], 0, 12) }}…</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin-v2.card>
    </div>

    <x-admin-v2.card title="Delivery Reports">
        @if($message->events->isEmpty())
            <p class="text-sm text-default-400">
                @if($message->provider_message_id)
                    Nothing from SMTP2Go yet.
                @else
                    SMTP2Go did not carry this email, so no reports will arrive.
                @endif
            </p>
        @else
            <ul class="flex flex-col gap-4 text-sm">
                @foreach($message->events->reverse() as $event)
                    <li>
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ $event->label() }}</span>
                            <span class="text-xs text-default-400" title="{{ $event->occurred_at->toIso8601String() }}">
                                {{ $event->occurred_at->format('M j, g:i A') }}
                            </span>
                        </div>
                        @if($event->recipient)
                            <p class="text-xs text-default-400">{{ $event->recipient }}</p>
                        @endif
                        @if($event->summary())
                            <p class="text-xs text-default-600 dark:text-default-300 mt-1">{{ $event->summary() }}</p>
                        @endif
                        <details class="text-xs text-default-400 mt-1">
                            <summary class="cursor-pointer">Payload</summary>
                            <pre class="bg-default-100 p-2 rounded mt-1 overflow-x-auto">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin-v2.card>
</div>
@endsection
