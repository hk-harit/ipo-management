<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Records every create / update / delete of a money record in audit_logs, with the
 * old and new values, so "who changed this amount?" always has an answer.
 *
 * Changes made with saveQuietly() (the derived status/shares_sold syncs from sales)
 * are not logged themselves — the sale or edit that caused them is.
 */
trait Auditable
{
    /** Never written to the log, whatever the model. */
    private static array $auditHidden = ['updated_at', 'created_at', 'password', 'remember_token', 'user_id', 'last_login_at'];

    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', [], $model->getAttributes()));

        static::updated(function ($model) {
            $changes = $model->getChanges();

            if (array_diff(array_keys($changes), static::$auditHidden) === []) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $field) {
                $old[$field] = $model->getOriginal($field);
            }

            $model->writeAudit('updated', $old, $changes);
        });

        static::deleted(fn ($model) => $model->writeAudit('deleted', $model->getAttributes(), []));
    }

    /** What the log calls this record. Models can override for something friendlier. */
    public function auditLabel(): string
    {
        return class_basename($this).' #'.$this->getKey().($this->getAttribute('name') ? ' — '.$this->getAttribute('name') : '');
    }

    private function writeAudit(string $event, array $old, array $new): void
    {
        $old = $this->filterAuditValues($old);
        $new = $this->filterAuditValues($new);

        if ($old === [] && $new === []) {
            return;
        }

        AuditLog::create([
            'user_id' => $this->getAttribute('user_id') ?? TenantContext::userId(),
            'actor_name' => Auth::guard('web')->user()?->name ?? (app()->runningInConsole() ? 'System' : null),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'label' => $this->auditLabel(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    private function filterAuditValues(array $values): array
    {
        $values = array_diff_key($values, array_flip(static::$auditHidden));

        // Money columns come in as typed ("900") or read back ("900.00"); store one format.
        foreach ($this->getCasts() as $field => $cast) {
            if (array_key_exists($field, $values) && $values[$field] !== null && str_starts_with((string) $cast, 'decimal:')) {
                $values[$field] = number_format((float) $values[$field], (int) substr($cast, 8), '.', '');
            }
        }

        return $values;
    }
}
