<aside class="app-menu" id="app-menu">
    <a href="{{ route('admin.home') }}" class="logo-box">
        <span class="logo logo-light">
            <span class="logo-lg text-lg font-semibold text-white">{{ config('app.name') }}</span>
            <span class="logo-sm text-lg font-semibold text-white">{{ \Illuminate\Support\Str::substr(config('app.name'), 0, 1) }}</span>
        </span>
        <span class="logo logo-dark">
            <span class="logo-lg text-lg font-semibold">{{ config('app.name') }}</span>
            <span class="logo-sm text-lg font-semibold">{{ \Illuminate\Support\Str::substr(config('app.name'), 0, 1) }}</span>
        </span>
    </a>

    <div class="h-topbar absolute end-5 top-0 flex items-center justify-end">
        <button id="button-hover-toggle">
            <span class="btn-on-hover-icon"></span>
        </button>
    </div>

    <div class="relative min-h-0 grow">
        <div class="size-full" data-simplebar>
            <div id="sidenav-menu">
                <ul class="side-nav hs-accordion-group px-2.5 pb-16">

                    <li class="menu-title mt-0!">Main</li>

                    <li class="menu-item">
                        <a href="{{ route('admin.home') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="layout-dashboard"></i></span>
                            <span class="menu-text">Dashboard</span>
                        </a>
                    </li>

                    @php
                        $canViewSystemSection = Gate::allows('viewAny', App\Models\User::class)
                            || Gate::allows('viewAny', App\Models\Role::class)
                            || Gate::allows('viewAny-audit-logs')
                            || Gate::allows('viewAny-queue-monitor')
                            || Gate::allows('viewAny-log-viewer')
                            || Gate::allows('viewAny-maintenance');
                    @endphp

                    @if($canViewSystemSection)
                    <li class="menu-title mt-2">System</li>

                    @can('viewAny', App\Models\User::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.users.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="user-cog"></i></span>
                            <span class="menu-text">User Management</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Role::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.roles.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="shield-check"></i></span>
                            <span class="menu-text">Roles &amp; Permissions</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny-audit-logs')
                    <li class="menu-item">
                        <a href="{{ route('admin.audit-logs.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="file-bar-chart-2"></i></span>
                            <span class="menu-text">Audit Logs</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny-queue-monitor')
                    <li class="menu-item">
                        <a href="{{ route('admin.queue-monitor.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="timer"></i></span>
                            <span class="menu-text">Queue Monitor</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny-log-viewer')
                    <li class="menu-item">
                        <a href="{{ route('admin.log-viewer.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="bar-chart-2"></i></span>
                            <span class="menu-text">Log Viewer</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny-maintenance')
                    <li class="menu-item">
                        <a href="{{ route('admin.maintenance.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="wrench"></i></span>
                            <span class="menu-text">Maintenance Mode</span>
                            @if(app()->isDownForMaintenance())
                                <span class="badge bg-warning ms-auto text-white text-xs">Active</span>
                            @endif
                        </a>
                    </li>
                    @endcan
                    @endif

                    @can('viewAny-billing')
                    <li class="menu-title mt-2">Billing</li>

                    <li class="menu-item">
                        <a href="{{ route('admin.billing.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="receipt"></i></span>
                            <span class="menu-text">Dashboard</span>
                        </a>
                    </li>

                    @can('viewAny', App\Models\Billing\Business::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.businesses.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="briefcase-business"></i></span>
                            <span class="menu-text">Businesses</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\Client::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.clients.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="users"></i></span>
                            <span class="menu-text">Clients</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\Project::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.projects.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="folder-kanban"></i></span>
                            <span class="menu-text">Projects</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\CostProvider::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.cost-providers.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="cloud"></i></span>
                            <span class="menu-text">Cost Providers</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\Invoice::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.invoices.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="receipt"></i></span>
                            <span class="menu-text">Invoices</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\RecurringLineTemplate::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.recurring-lines.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="rotate-cw"></i></span>
                            <span class="menu-text">Recurring Items</span>
                        </a>
                    </li>
                    @endcan

                    @can('viewAny', App\Models\Billing\Invoice::class)
                    <li class="menu-item">
                        <a href="{{ route('admin.billing.receivables.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="hand-coins"></i></span>
                            <span class="menu-text">Receivables</span>
                        </a>
                    </li>
                    @endcan

                    <li class="menu-item">
                        <a href="{{ route('admin.billing.reconciliation.index') }}" class="menu-link">
                            <span class="menu-icon"><i data-lucide="scale"></i></span>
                            <span class="menu-text">Reconciliation</span>
                        </a>
                    </li>
                    @endcan

                </ul>
            </div>
        </div>
    </div>
</aside>
