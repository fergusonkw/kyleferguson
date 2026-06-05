<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    /**
     * Log an action performed on a model.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $tags
     */
    public function log(
        string $event,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $tags = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $this->currentUser()?->id,
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'tags' => $tags,
        ]);
    }

    /**
     * Log a critical action (automatically tagged as critical).
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $tags
     */
    public function logCritical(
        string $event,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $tags = null
    ): AuditLog {
        return $this->log($event, $model, $oldValues, $newValues, array_merge($tags ?? [], ['critical']));
    }

    /**
     * @param  array<int, string>|null  $tags
     */
    public function logCreated(Model $model, ?array $tags = null): AuditLog
    {
        return $this->log('created', $model, null, $this->getModelAttributes($model), $tags);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<int, string>|null  $tags
     */
    public function logUpdated(Model $model, array $oldValues, ?array $tags = null): AuditLog
    {
        return $this->log('updated', $model, $oldValues, $this->getModelAttributes($model), $tags);
    }

    /**
     * @param  array<int, string>|null  $tags
     */
    public function logDeleted(Model $model, ?array $tags = null): AuditLog
    {
        return $this->log('deleted', $model, $this->getModelAttributes($model), null, $tags);
    }

    /**
     * Log an authentication event. Tagged 'security' + 'auth' for filtering.
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<int, string>|null  $tags
     */
    public function logAuth(
        string $event,
        ?User $user = null,
        ?array $context = null,
        ?array $tags = null,
    ): AuditLog {
        $tags = array_values(array_unique(array_merge($tags ?? [], ['security', 'auth'])));

        return AuditLog::create([
            'user_id' => $user?->id,
            'event' => $event,
            'auditable_type' => $user ? User::class : null,
            'auditable_id' => $user?->getKey(),
            'old_values' => null,
            'new_values' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'tags' => $tags,
        ]);
    }

    /**
     * Log a security event (automatically tagged as security).
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<int, string>|null  $tags
     */
    public function logSecurity(
        string $event,
        ?Model $model = null,
        ?array $context = null,
        ?array $tags = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $this->currentUser()?->id,
            'event' => $event,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => null,
            'new_values' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'tags' => array_merge($tags ?? [], ['security']),
        ]);
    }

    private function currentUser(): ?User
    {
        $user = Auth::user() ?? Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getModelAttributes(Model $model): array
    {
        $attributes = $model->getAttributes();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $sensitive) {
            unset($attributes[$sensitive]);
        }

        return $attributes;
    }
}
