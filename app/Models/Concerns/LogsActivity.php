<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every create / update / delete on a model into the audit_logs table
 * with the user, IP, user agent and before/after values — satisfying the
 * "complete audit log for all data changes" requirement. GPS at status change
 * is captured separately in booking_status_histories.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn (Model $model) => $model->writeAuditLog('created', null, $model->auditableAttributes()));

        static::updated(function (Model $model) {
            $changes = $model->auditableChanges();
            if (empty($changes['new'])) {
                return; // Nothing meaningful changed.
            }
            $model->writeAuditLog('updated', $changes['old'], $changes['new']);
        });

        static::deleted(fn (Model $model) => $model->writeAuditLog('deleted', $model->auditableAttributes(), null));
    }

    /** Attributes excluded from the audit record (noise / secrets). */
    protected function auditExcluded(): array
    {
        return array_merge(['created_at', 'updated_at', 'remember_token', 'password'], $this->hidden ?? []);
    }

    protected function auditableAttributes(): array
    {
        return collect($this->getAttributes())
            ->except($this->auditExcluded())
            ->all();
    }

    /** @return array{old: array, new: array} */
    protected function auditableChanges(): array
    {
        $changed = collect($this->getChanges())->except($this->auditExcluded());

        return [
            'old' => collect($this->getOriginal())->only($changed->keys())->all(),
            'new' => $changed->all(),
        ];
    }

    protected function writeAuditLog(string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) (request()->userAgent() ?? ''), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable', 'auditable_type', 'auditable_id');
    }
}
