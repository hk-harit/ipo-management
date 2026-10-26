<?php

namespace App\Models\Concerns;

/**
 * Accounts, applicants, IPOs and sources are referenced by money records, and
 * the database cascades deletes — so removing one would silently erase every
 * application and transaction that points at it. This vetoes the delete while
 * any such history exists; the records must be dealt with first.
 */
trait BlocksDeleteWithHistory
{
    /** Why this record cannot be deleted right now, or null when it is safe to. */
    abstract public function deletionBlockedReason(): ?string;

    public static function bootBlocksDeleteWithHistory(): void
    {
        static::deleting(function ($model) {
            return $model->deletionBlockedReason() === null ? null : false;
        });
    }

    /** "3 applications and 2 transactions" from [label => count], skipping zeroes. */
    protected function describeHistory(array $counts): ?string
    {
        $parts = [];

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$label;
            }
        }

        if ($parts === []) {
            return null;
        }

        return 'It still has '.implode(', ', $parts).'. Delete or move those first, or mark it inactive instead.';
    }
}
