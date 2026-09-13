@extends('admin-v2.layouts.vertical', ['title' => 'Email Log'])

@section('content')
<x-admin-v2.page-title
    title="Email Log"
    :breadcrumbs="[['label' => 'Email Log', 'active' => true]]"
/>

<x-admin-v2.card title="Sent Email" subtitle="Every email the application has sent, and what SMTP2Go reported back.">
    <form method="GET" action="{{ route('admin.email-log.index') }}" class="mb-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
            <div>
                <label for="search" class="form-label text-xs">Recipient or subject</label>
                <input type="text" name="search" id="search" class="form-input form-input-sm" value="{{ request('search') }}">
            </div>
            <div>
                <label for="status" class="form-label text-xs">Status</label>
                <select name="status" id="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
                <i data-lucide="filter" class="size-4 me-1"></i> Apply Filters
            </button>
            <a href="{{ route('admin.email-log.index') }}" class="btn btn-light btn-sm">
                <i data-lucide="x" class="size-4 me-1"></i> Clear Filters
            </a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="table w-full text-sm">
            <thead>
                <tr>
                    <th class="w-36">Sent</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th class="w-32">About</th>
                    <th class="w-32">Status</th>
                    <th class="text-center w-20">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                    <tr>
                        <td class="text-xs text-nowrap">{{ ($message->sent_at ?? $message->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="text-xs break-all">{{ $message->to_address }}</td>
                        <td class="text-xs">{{ $message->subject }}</td>
                        <td class="text-xs">@include('admin-v2.email-log.partials.related')</td>
                        <td>
                            <span class="badge bg-{{ $message->status->badgeColor() }}">{{ $message->status->label() }}</span>
                            @unless($message->wasDelivered())
                                <span class="badge bg-default/15 text-default-500 text-xs" title="Written to the {{ $message->mailer }} mailer, not delivered">{{ $message->mailer }}</span>
                            @endunless
                        </td>
                        <td class="text-center">
                            <a href="{{ route('admin.email-log.show', $message) }}" class="btn btn-xs btn-light" title="View email">
                                <i data-lucide="eye" class="size-3"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-default-400 py-8">
                            <i data-lucide="mail-search" class="size-8 mx-auto mb-2 opacity-40"></i>
                            <p>No emails match these filters.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $messages->links() }}</div>

    <div class="border-t border-default-200 mt-4 pt-3 flex justify-between text-xs text-default-400">
        <span>Showing {{ $messages->firstItem() ?? 0 }} to {{ $messages->lastItem() ?? 0 }} of {{ $messages->total() }} emails</span>
        <span>Page {{ $messages->currentPage() }} of {{ $messages->lastPage() }}</span>
    </div>
</x-admin-v2.card>
@endsection
