<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    // User Management
    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case DeleteUsers = 'users.delete';

    // Role & Permission Management
    case ViewRoles = 'roles.view';
    case ManageRoles = 'roles.manage';

    // Operations / Observability
    case ViewAuditLogs = 'audit-logs.view';
    case ViewLogViewer = 'log-viewer.view';
    case ViewQueueMonitor = 'queue-monitor.view';
    case ManageMaintenance = 'maintenance.manage';

    // Billing
    case ViewBilling = 'billing.view';
    case ManageBusinesses = 'billing.businesses.manage';
    case ManageClients = 'billing.clients.manage';
    case ManageProjects = 'billing.projects.manage';
    case ManageCostProviders = 'billing.cost-providers.manage';

    /**
     * All permission slugs.
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }

    /**
     * The category this permission belongs to (used for grouping in the UI).
     */
    public function category(): string
    {
        return match ($this) {
            self::ViewUsers, self::CreateUsers, self::UpdateUsers, self::DeleteUsers => 'User Management',
            self::ViewRoles, self::ManageRoles => 'Role Management',
            self::ViewAuditLogs, self::ViewLogViewer, self::ViewQueueMonitor, self::ManageMaintenance => 'Operations',
            self::ViewBilling, self::ManageBusinesses, self::ManageClients, self::ManageProjects, self::ManageCostProviders => 'Billing',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewUsers => 'View users',
            self::CreateUsers => 'Create users',
            self::UpdateUsers => 'Update users',
            self::DeleteUsers => 'Delete users',
            self::ViewRoles => 'View roles',
            self::ManageRoles => 'Manage roles & permissions',
            self::ViewAuditLogs => 'View audit logs',
            self::ViewLogViewer => 'View application logs',
            self::ViewQueueMonitor => 'View queue monitor',
            self::ManageMaintenance => 'Manage maintenance mode',
            self::ViewBilling => 'View billing dashboard',
            self::ManageBusinesses => 'Manage businesses',
            self::ManageClients => 'Manage clients',
            self::ManageProjects => 'Manage projects',
            self::ManageCostProviders => 'Manage cost providers',
        };
    }
}
