@extends('admin-v2.layouts.vertical', ['title' => 'Queue Monitor'])

@section('content')
<x-admin-v2.page-title
    title="Queue Monitor"
    :breadcrumbs="[['label' => 'Queue Monitor', 'active' => true]]"
/>

<div class="mb-5">
    <div class="border-b border-default-200">
        <nav class="flex gap-4" role="tablist">
            <button type="button" role="tab" data-tab="active-jobs"
                    class="tab-btn pb-3 text-sm font-medium border-b-2 border-primary text-primary -mb-px">
                <i data-lucide="clock" class="size-4 me-1 inline"></i> Active Jobs
            </button>
            <button type="button" role="tab" data-tab="failed-jobs"
                    class="tab-btn pb-3 text-sm font-medium border-b-2 border-transparent text-default-500 -mb-px">
                <i data-lucide="triangle-alert" class="size-4 me-1 inline"></i> Failed Jobs
            </button>
        </nav>
    </div>

    {{-- Active Jobs --}}
    <div id="tab-active-jobs" class="tab-panel pt-4">
        <x-admin-v2.card>
            <x-slot:headerActions>
                <div class="flex gap-2 items-center">
                    <select id="activeQueueFilter" class="form-select form-select-sm w-48">
                        <option value="">All Queues</option>
                    </select>
                    <button id="refreshActiveJobs" class="btn btn-sm btn-light">
                        <i data-lucide="refresh-cw" class="size-4 me-1"></i> Refresh
                    </button>
                </div>
            </x-slot:headerActions>

            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job Name</th>
                            <th>Queue</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Created At</th>
                            <th>Reserved At</th>
                        </tr>
                    </thead>
                    <tbody id="activeJobsBody">
                        <tr><td colspan="7" class="text-center py-4">
                            <span class="inline-block size-5 animate-spin rounded-full border-2 border-primary border-t-transparent"></span>
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div id="activeJobsPagination" class="mt-3"></div>
        </x-admin-v2.card>
    </div>

    {{-- Failed Jobs --}}
    <div id="tab-failed-jobs" class="tab-panel pt-4 hidden">
        <x-admin-v2.card>
            <x-slot:headerActions>
                <div class="flex gap-2 items-center">
                    <select id="failedQueueFilter" class="form-select form-select-sm w-48">
                        <option value="">All Queues</option>
                    </select>
                    <button id="refreshFailedJobs" class="btn btn-sm btn-light">
                        <i data-lucide="refresh-cw" class="size-4 me-1"></i> Refresh
                    </button>
                    <button id="retryAllFailedJobs" class="btn btn-sm btn-success">
                        <i data-lucide="rotate-cw" class="size-4 me-1"></i> Retry All
                    </button>
                    <button id="flushFailedJobs" class="btn btn-sm btn-danger">
                        <i data-lucide="trash-2" class="size-4 me-1"></i> Delete All
                    </button>
                </div>
            </x-slot:headerActions>

            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job Name</th>
                            <th>Queue</th>
                            <th>Failed At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="failedJobsBody">
                        <tr><td colspan="5" class="text-center py-4">
                            <span class="inline-block size-5 animate-spin rounded-full border-2 border-primary border-t-transparent"></span>
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div id="failedJobsPagination" class="mt-3"></div>
        </x-admin-v2.card>
    </div>
</div>

{{-- Error Details Offcanvas --}}
<x-admin-v2.offcanvas canvasId="errorDetailsOffcanvas" title="Error Details" size="lg">
    <div id="errorDetailsContent">
        <div class="mb-4">
            <h6 class="text-xs font-semibold text-default-500 uppercase mb-2">Job Information</h6>
            <dl class="text-sm space-y-2">
                <div class="flex gap-2"><dt class="font-medium w-24 shrink-0">Job Name:</dt><dd id="errorJobName"></dd></div>
                <div class="flex gap-2"><dt class="font-medium w-24 shrink-0">Queue:</dt><dd id="errorQueue"></dd></div>
                <div class="flex gap-2"><dt class="font-medium w-24 shrink-0">Failed At:</dt><dd id="errorFailedAt"></dd></div>
            </dl>
        </div>
        <div class="mb-4">
            <h6 class="text-xs font-semibold text-default-500 uppercase mb-2">Exception Message</h6>
            <div class="rounded p-3 bg-danger/10 text-danger text-sm" id="errorMessage"></div>
        </div>
        <div>
            <h6 class="text-xs font-semibold text-default-500 uppercase mb-2">Stack Trace</h6>
            <pre class="bg-default-100 p-3 rounded text-xs max-h-96 overflow-y-auto"><code id="errorStackTrace"></code></pre>
        </div>
    </div>
</x-admin-v2.offcanvas>
@endsection

@push('scripts')
<script>
(function () {
    let activeJobsCurrentPage = 1;
    let failedJobsCurrentPage = 1;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Built from named routes. These endpoints live under the /admin prefix,
    // and hardcoding the paths without it silently 404'd every panel on this
    // page — the tables just rendered their error state forever.
    const URLS = {
        queues: @json(route('admin.queue-monitor.queues'), JSON_UNESCAPED_SLASHES),
        jobs: @json(route('admin.queue-monitor.jobs'), JSON_UNESCAPED_SLASHES),
        failedJobs: @json(route('admin.queue-monitor.failed-jobs'), JSON_UNESCAPED_SLASHES),
        retryAll: @json(route('admin.queue-monitor.retry-all'), JSON_UNESCAPED_SLASHES),
        flush: @json(route('admin.queue-monitor.flush'), JSON_UNESCAPED_SLASHES),
        retry: (uuid) => @json(route('admin.queue-monitor.retry', ['uuid' => '__UUID__']), JSON_UNESCAPED_SLASHES).replace('__UUID__', encodeURIComponent(uuid)),
        remove: (uuid) => @json(route('admin.queue-monitor.delete', ['uuid' => '__UUID__']), JSON_UNESCAPED_SLASHES).replace('__UUID__', encodeURIComponent(uuid)),
    };

    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('border-primary', 'text-primary');
                b.classList.add('border-transparent', 'text-default-500');
            });
            this.classList.add('border-primary', 'text-primary');
            this.classList.remove('border-transparent', 'text-default-500');

            document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
            document.getElementById('tab-' + this.dataset.tab).classList.remove('hidden');
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        loadQueues();
        loadActiveJobs();
        loadFailedJobs();

        document.getElementById('refreshActiveJobs').addEventListener('click', () => { activeJobsCurrentPage = 1; loadActiveJobs(); });
        document.getElementById('refreshFailedJobs').addEventListener('click', () => { failedJobsCurrentPage = 1; loadFailedJobs(); });
        document.getElementById('activeQueueFilter').addEventListener('change', () => { activeJobsCurrentPage = 1; loadActiveJobs(); });
        document.getElementById('failedQueueFilter').addEventListener('change', () => { failedJobsCurrentPage = 1; loadFailedJobs(); });

        document.getElementById('retryAllFailedJobs').addEventListener('click', () => {
            if (confirm('Are you sure you want to retry all failed jobs?')) { retryAllFailedJobs(); }
        });
        document.getElementById('flushFailedJobs').addEventListener('click', () => {
            if (confirm('Are you sure you want to delete all failed jobs? This cannot be undone.')) { flushFailedJobs(); }
        });
    });

    function loadQueues() {
        fetch(URLS.queues).then(r => r.json()).then(queues => {
            const activeSelect = document.getElementById('activeQueueFilter');
            const failedSelect = document.getElementById('failedQueueFilter');
            queues.forEach(queue => {
                activeSelect.add(new Option(queue, queue));
                failedSelect.add(new Option(queue, queue));
            });
        }).catch(console.error);
    }

    function loadActiveJobs(page = 1) {
        activeJobsCurrentPage = page;
        const queue = document.getElementById('activeQueueFilter').value;
        fetch(`${URLS.jobs}?page=${page}${queue ? `&queue=${queue}` : ''}`)
            .then(r => r.json())
            .then(data => { renderActiveJobs(data.data); renderPagination(data, 'activeJobsPagination', loadActiveJobs); })
            .catch(() => { document.getElementById('activeJobsBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading jobs</td></tr>'; });
    }

    function loadFailedJobs(page = 1) {
        failedJobsCurrentPage = page;
        const queue = document.getElementById('failedQueueFilter').value;
        fetch(`${URLS.failedJobs}?page=${page}${queue ? `&queue=${queue}` : ''}`)
            .then(r => r.json())
            .then(data => { renderFailedJobs(data.data); renderPagination(data, 'failedJobsPagination', loadFailedJobs); })
            .catch(() => { document.getElementById('failedJobsBody').innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading failed jobs</td></tr>'; });
    }

    function renderActiveJobs(jobs) {
        const tbody = document.getElementById('activeJobsBody');
        if (!jobs.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center text-default-400">No active jobs</td></tr>'; return; }
        tbody.innerHTML = jobs.map(job => `
            <tr>
                <td>${job.id}</td>
                <td><code class="text-xs">${job.job_name}</code></td>
                <td><span class="badge bg-info">${job.queue}</span></td>
                <td>${getStatusBadge(job.status)}</td>
                <td>${job.attempts}</td>
                <td class="text-sm text-default-400">${job.created_at || '-'}</td>
                <td class="text-sm text-default-400">${job.reserved_at || '-'}</td>
            </tr>`).join('');
    }

    function renderFailedJobs(jobs) {
        const tbody = document.getElementById('failedJobsBody');
        if (!jobs.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-center text-default-400">No failed jobs</td></tr>'; return; }
        tbody.innerHTML = jobs.map(job => `
            <tr>
                <td>${job.id}</td>
                <td><code class="text-xs">${escapeHtml(job.job_name)}</code></td>
                <td><span class="badge bg-info">${job.queue}</span></td>
                <td class="text-sm text-default-400">${job.failed_at}</td>
                <td>
                    <div class="flex gap-1">
                        <button class="btn btn-xs btn-light view-error-btn"
                                data-job-name="${escapeHtml(job.job_name)}"
                                data-queue="${job.queue}"
                                data-failed-at="${job.failed_at}"
                                data-error-message="${escapeHtml(job.exception.message)}"
                                data-stack-trace="${escapeHtml(job.exception.stack_trace)}"
                                title="View Error">
                            <i data-lucide="eye" class="size-3"></i>
                        </button>
                        <button class="btn btn-xs btn-success retry-job-btn" data-uuid="${job.uuid}" title="Retry">
                            <i data-lucide="rotate-cw" class="size-3"></i>
                        </button>
                        <button class="btn btn-xs btn-danger delete-job-btn" data-uuid="${job.uuid}" title="Delete">
                            <i data-lucide="trash-2" class="size-3"></i>
                        </button>
                    </div>
                </td>
            </tr>`).join('');
        if (window.lucide) { lucide.createIcons({ el: tbody }); }
    }

    function getStatusBadge(status) {
        const badges = { pending: 'bg-secondary', processing: 'bg-primary', delayed: 'bg-warning' };
        return `<span class="badge ${badges[status] || 'bg-secondary'}">${status || 'Unknown'}</span>`;
    }

    function renderPagination(data, containerId, loadFn) {
        const container = document.getElementById(containerId);
        if (!data.links || data.links.length <= 3) { container.innerHTML = ''; return; }
        container.innerHTML = `<nav><ul class="flex gap-1 justify-end">
            ${data.links.map(link => {
                if (!link.url) { return `<li><span class="btn btn-xs btn-light opacity-50">${link.label}</span></li>`; }
                const page = new URL(link.url).searchParams.get('page') || 1;
                return `<li><button class="btn btn-xs ${link.active ? 'btn-primary' : 'btn-light'}" onclick="${loadFn.name}(${page})">${link.label}</button></li>`;
            }).join('')}
        </ul></nav>`;
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.view-error-btn')) {
            const btn = e.target.closest('.view-error-btn');
            document.getElementById('errorJobName').textContent = unescapeHtml(btn.dataset.jobName);
            document.getElementById('errorQueue').textContent = btn.dataset.queue;
            document.getElementById('errorFailedAt').textContent = btn.dataset.failedAt;
            document.getElementById('errorMessage').textContent = unescapeHtml(btn.dataset.errorMessage);
            document.getElementById('errorStackTrace').textContent = unescapeHtml(btn.dataset.stackTrace);
            HSOverlay.open('#errorDetailsOffcanvas');
        }
        if (e.target.closest('.retry-job-btn')) {
            const uuid = e.target.closest('.retry-job-btn').dataset.uuid;
            if (confirm('Retry this job?')) { retryJob(uuid); }
        }
        if (e.target.closest('.delete-job-btn')) {
            const uuid = e.target.closest('.delete-job-btn').dataset.uuid;
            if (confirm('Delete this failed job? This cannot be undone.')) { deleteJob(uuid); }
        }
    });

    function retryJob(uuid) {
        fetch(URLS.retry(uuid), { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
            .then(r => r.json()).then(data => { Alert.toast(data.message, 'success'); loadFailedJobs(failedJobsCurrentPage); loadActiveJobs(activeJobsCurrentPage); })
            .catch(() => Alert.error('Failed to retry job'));
    }
    function deleteJob(uuid) {
        fetch(URLS.remove(uuid), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
            .then(r => r.json()).then(data => { Alert.toast(data.message, 'success'); loadFailedJobs(failedJobsCurrentPage); })
            .catch(() => Alert.error('Failed to delete job'));
    }
    function retryAllFailedJobs() {
        fetch(URLS.retryAll, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
            .then(r => r.json()).then(data => { Alert.toast(data.message, 'success'); loadFailedJobs(1); loadActiveJobs(1); })
            .catch(() => Alert.error('Failed to retry jobs'));
    }
    function flushFailedJobs() {
        fetch(URLS.flush, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
            .then(r => r.json()).then(data => { Alert.toast(data.message, 'success'); loadFailedJobs(1); })
            .catch(() => Alert.error('Failed to delete jobs'));
    }
    function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text || ''; return d.innerHTML; }
    function unescapeHtml(text) { const d = document.createElement('div'); d.innerHTML = text || ''; return d.textContent; }
})();
</script>
@endpush
