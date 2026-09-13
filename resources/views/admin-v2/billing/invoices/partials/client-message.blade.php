{{-- The note the invoice email carries, in the operator's own words. Written
     until the first send; after that it records what the client received and
     changes only with a resend. --}}
@if($invoice->acceptsClientMessage() && auth()->user()?->can('writeClientMessage', $invoice))
    <x-admin-v2.card title="Message to client" class="mt-5">
        {{-- Submitting the form opens the preview in a new tab. A real form
             post, so a popup blocker cannot stop it; Save goes by fetch. --}}
        <form id="clientMessageForm" method="POST" target="_blank"
              action="{{ route('admin.billing.invoices.email-preview', $invoice) }}">
            @csrf
            <x-admin-v2.form.textarea name="client_message" rows="6" :value="$invoice->client_message ?? ''"
                placeholder="A few words for the client: what this month covered, what changed, anything they should know."
                help="Goes in the invoice email, under the greeting. Plain text — a blank line starts a new paragraph. Leave it empty for the standard wording." />
            <div class="flex gap-2 justify-end">
                <button type="submit" class="btn btn-sm btn-light">
                    <i data-lucide="eye" class="size-4 me-1"></i> Preview email
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="saveClientMessageBtn">
                    <i data-lucide="check" class="size-4 me-1"></i> Save message
                </button>
            </div>
        </form>
    </x-admin-v2.card>
@elseif(filled($invoice->client_message))
    <x-admin-v2.card title="Message to client" class="mt-5">
        <p class="text-sm whitespace-pre-line">{{ $invoice->client_message }}</p>
        @if($invoice->sent_at !== null)
            <p class="text-xs text-default-400 mt-3 mb-0">Sent with the invoice. Resending lets you change it.</p>
        @endif
    </x-admin-v2.card>
@endif
