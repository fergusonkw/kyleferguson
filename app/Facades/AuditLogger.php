<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Models\AuditLog log(string $event, \Illuminate\Database\Eloquent\Model $model, ?array $oldValues = null, ?array $newValues = null, ?array $tags = null)
 * @method static \App\Models\AuditLog logCritical(string $event, \Illuminate\Database\Eloquent\Model $model, ?array $oldValues = null, ?array $newValues = null, ?array $tags = null)
 * @method static \App\Models\AuditLog logCreated(\Illuminate\Database\Eloquent\Model $model, ?array $tags = null)
 * @method static \App\Models\AuditLog logUpdated(\Illuminate\Database\Eloquent\Model $model, array $oldValues, ?array $tags = null)
 * @method static \App\Models\AuditLog logDeleted(\Illuminate\Database\Eloquent\Model $model, ?array $tags = null)
 * @method static \App\Models\AuditLog logAuth(string $event, ?\App\Models\User $user = null, ?array $context = null, ?array $tags = null)
 * @method static \App\Models\AuditLog logSecurity(string $event, ?\Illuminate\Database\Eloquent\Model $model = null, ?array $context = null, ?array $tags = null)
 *
 * @see \App\Services\AuditLogger
 */
final class AuditLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\AuditLogger::class;
    }
}
