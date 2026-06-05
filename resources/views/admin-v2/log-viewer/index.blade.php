@extends('admin-v2.layouts.vertical', ['title' => 'Log Viewer'])

@section('content')
<x-admin-v2.page-title
    title="Log Viewer"
    :breadcrumbs="[['label' => 'Log Viewer', 'active' => true]]"
/>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
    {{-- Log Files Sidebar --}}
    <div>
        <x-admin-v2.card title="Log Files">
            @if($logFiles->isEmpty())
                <p class="text-default-400 text-sm">No log files found.</p>
            @else
                <div class="space-y-1">
                    @foreach($logFiles as $file)
                        <a href="{{ route('admin.log-viewer.index', ['file' => $file['filename']]) }}"
                           class="block p-2 rounded text-sm {{ $selectedFile === $file['filename'] ? 'bg-primary/10 border border-primary/20 text-primary' : 'hover:bg-default-100' }}">
                            <div class="flex items-center gap-2">
                                <i data-lucide="file-text" class="size-4 shrink-0"></i>
                                <span class="truncate font-medium">{{ $file['filename'] }}</span>
                            </div>
                            <p class="text-xs text-default-400 mt-1 ms-6">{{ $file['size'] }} · {{ $file['modified'] }}</p>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin-v2.card>
    </div>

    {{-- Log Entries --}}
    <div class="lg:col-span-3">
        <x-admin-v2.card title="{{ $selectedFile ? 'Entries: ' . $selectedFile : 'Select a Log File' }}">
            @if(empty($entries))
                <div class="text-center text-default-400 py-10">
                    <i data-lucide="file-search" class="size-10 mx-auto mb-3 opacity-40"></i>
                    @if($selectedFile)
                        <p>No log entries found in this file.</p>
                    @else
                        <p>Select a log file from the sidebar to view its entries.</p>
                    @endif
                </div>
            @else
                @if($truncated)
                    <x-admin-v2.alert type="warning" class="mb-3">
                        This file exceeds 10 MB. Only the most recent entries are shown.
                    </x-admin-v2.alert>
                @endif

                <div class="mb-3">
                    <input type="text" id="logSearch" class="form-control form-control-sm" placeholder="Filter log entries...">
                </div>

                <div class="overflow-x-auto">
                    <table class="table w-full text-sm" id="logTable">
                        <thead>
                            <tr>
                                <th class="w-40">Timestamp</th>
                                <th class="w-24">Level</th>
                                <th class="w-24">Environment</th>
                                <th>Message</th>
                                <th class="text-center w-16">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $index => $entry)
                                @php
                                    $levelClass = match($entry['level']) {
                                        'EMERGENCY', 'ALERT', 'CRITICAL' => 'bg-danger',
                                        'ERROR' => 'bg-danger/15 text-danger',
                                        'WARNING' => 'bg-warning/15 text-warning',
                                        'NOTICE' => 'bg-info/15 text-info',
                                        'INFO' => 'bg-primary/15 text-primary',
                                        'DEBUG' => 'bg-secondary/15 text-secondary',
                                        default => 'bg-secondary',
                                    };
                                @endphp
                                <tr class="log-row"
                                    data-search="{{ strtolower($entry['timestamp'] . ' ' . $entry['level'] . ' ' . $entry['message']) }}">
                                    <td class="text-nowrap text-xs text-default-400">{{ $entry['timestamp'] }}</td>
                                    <td><span class="badge {{ $levelClass }}">{{ $entry['level'] }}</span></td>
                                    <td class="text-xs text-default-400">{{ $entry['environment'] }}</td>
                                    <td class="text-xs">
                                        <span class="block truncate max-w-lg" title="{{ $entry['message'] }}">
                                            {{ $entry['message'] }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-xs btn-light view-log-detail"
                                                data-file="{{ $selectedFile }}"
                                                data-index="{{ $index }}"
                                                title="View Details">
                                            <i data-lucide="eye" class="size-3"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-default-200 mt-3 pt-3">
                    <p class="text-xs text-default-400">Showing {{ count($entries) }} log {{ count($entries) === 1 ? 'entry' : 'entries' }}</p>
                </div>
            @endif
        </x-admin-v2.card>
    </div>
</div>

{{-- Log Detail Offcanvas --}}
<x-admin-v2.offcanvas canvasId="logDetailCanvas" title="Log Entry Details" size="lg">
    <x-slot:actions>
        <button type="button"
                id="copyLogBtn"
                class="btn btn-xs btn-light hidden"
                title="Copy to clipboard">
            <i data-lucide="copy" class="size-3.5"></i>
            <span>Copy</span>
        </button>
    </x-slot:actions>

    <div id="logDetailContent">
        <div class="text-center text-default-400 py-6">
            <span class="inline-block size-6 animate-spin rounded-full border-2 border-primary border-t-transparent"></span>
            <p class="mt-2 text-sm">Loading log entry...</p>
        </div>
    </div>
</x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('logSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase();
            document.querySelectorAll('.log-row').forEach(row => {
                row.style.display = (row.getAttribute('data-search') || '').includes(query) ? '' : 'none';
            });
        });
    }

    const detailContent = document.getElementById('logDetailContent');
    const copyLogBtn = document.getElementById('copyLogBtn');

    // Track the current log entry data for clipboard use
    let currentLogData = null;

    // Copy to clipboard
    if (copyLogBtn) {
        copyLogBtn.addEventListener('click', function () {
            if (!currentLogData) { return; }

            const parts = [
                `Level:       ${currentLogData.level}`,
                `Environment: ${currentLogData.environment}`,
                `Timestamp:   ${currentLogData.timestamp}`,
                `Message:     ${currentLogData.message}`,
            ];
            if (currentLogData.stack_trace) {
                parts.push(`\nStack Trace:\n${currentLogData.stack_trace}`);
            }
            const text = parts.join('\n');

            copyToClipboard(text, copyLogBtn);
        });
    }

    document.querySelectorAll('.view-log-detail').forEach(btn => {
        btn.addEventListener('click', function () {
            const file = this.dataset.file;
            const index = this.dataset.index;

            currentLogData = null;
            if (copyLogBtn) { copyLogBtn.classList.add('hidden'); }

            detailContent.innerHTML = '<div class="text-center text-default-400 py-6"><span class="inline-block size-6 animate-spin rounded-full border-2 border-primary border-t-transparent"></span><p class="mt-2 text-sm">Loading...</p></div>';
            HSOverlay.open('#logDetailCanvas');

            fetch('{{ route("admin.log-viewer.show") }}?file=' + encodeURIComponent(file) + '&index=' + encodeURIComponent(index), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) { detailContent.innerHTML = `<div class="rounded p-3 bg-danger/10 text-danger text-sm">${data.error}</div>`; return; }

                currentLogData = data;
                if (copyLogBtn) { copyLogBtn.classList.remove('hidden'); }

                const levelColors = {
                    EMERGENCY: 'bg-danger', ALERT: 'bg-danger', CRITICAL: 'bg-danger',
                    ERROR: 'bg-danger/15 text-danger', WARNING: 'bg-warning/15 text-warning',
                    NOTICE: 'bg-info/15 text-info', INFO: 'bg-primary/15 text-primary',
                    DEBUG: 'bg-secondary/15 text-secondary'
                };
                const lvlClass = levelColors[data.level] || 'bg-secondary';

                let html = `<div class="mb-3 flex items-center gap-2"><span class="badge ${lvlClass}">${esc(data.level)}</span><span class="text-sm text-default-400">${esc(data.environment)}</span></div>`;
                html += `<div class="mb-3"><p class="text-xs font-semibold mb-1">Timestamp</p><p class="text-sm">${esc(data.timestamp)}</p></div>`;
                html += `<div class="mb-3"><p class="text-xs font-semibold mb-1">Message</p><div class="p-3 rounded bg-default-100 text-sm break-words">${esc(data.message)}</div></div>`;
                if (data.stack_trace) {
                    html += `<div><p class="text-xs font-semibold mb-1">Stack Trace</p><pre class="bg-default-100 p-3 rounded text-xs max-h-96 overflow-auto whitespace-pre-wrap break-all">${esc(data.stack_trace)}</pre></div>`;
                }
                detailContent.innerHTML = html;
            })
            .catch(() => {
                currentLogData = null;
                if (copyLogBtn) { copyLogBtn.classList.add('hidden'); }
                detailContent.innerHTML = '<div class="rounded p-3 bg-danger/10 text-danger text-sm">Failed to load log entry details.</div>';
            });
        });
    });

    function copyToClipboard(text, btn) {
        const onSuccess = () => {
            const icon = btn.querySelector('i[data-lucide]');
            const label = btn.querySelector('span');
            if (icon) { icon.setAttribute('data-lucide', 'check'); lucide.createIcons({ el: btn }); }
            if (label) { label.textContent = 'Copied!'; }
            setTimeout(() => {
                if (icon) { icon.setAttribute('data-lucide', 'copy'); lucide.createIcons({ el: btn }); }
                if (label) { label.textContent = 'Copy'; }
            }, 2000);
        };
        const onError = () => Alert.toast('Failed to copy to clipboard.', 'error');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess).catch(onError);
        } else {
            // Fallback for non-secure contexts (HTTP)
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try {
                document.execCommand('copy') ? onSuccess() : onError();
            } catch {
                onError();
            } finally {
                document.body.removeChild(ta);
            }
        }
    }

    function esc(text) { const d = document.createElement('div'); d.appendChild(document.createTextNode(text || '')); return d.innerHTML; }
});
</script>
@endpush
