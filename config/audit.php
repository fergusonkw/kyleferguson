<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | Audit logs older than this many days are removed by the scheduled
    | `audit-logs:prune` command (see routes/console.php).
    |
    */
    'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 90),
];
