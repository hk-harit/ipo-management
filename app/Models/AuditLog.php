<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** One recorded change to a money record. Append-only. */
class AuditLog extends Model
{
    use BelongsToUser;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_name',
        'auditable_type',
        'auditable_id',
        'event',
        'label',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** "IpoApplication" from the stored class name. */
    public function getModelNameAttribute(): string
    {
        return class_basename($this->auditable_type);
    }

    /** Readable "field: old → new" lines for the change. */
    public function changeLines(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $lines = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $from = $old[$field] ?? null;
            $to = $new[$field] ?? null;

            $lines[] = match ($this->event) {
                'created' => $field.': '.static::show($to),
                'deleted' => $field.': '.static::show($from),
                default => $field.': '.static::show($from).' → '.static::show($to),
            };
        }

        return $lines;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
