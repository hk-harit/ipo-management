<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenancy, the simple way: every row belongs to exactly one login (User),
 * and that login only ever sees its own rows. No shared/reference data, no
 * tenant switching — each user's Sources, Accounts, IPOs, etc. are fully separate.
 *
 * - New records are stamped with the current user automatically.
 * - Every query on the model is silently scoped to the current tenant — the
 *   admin themself, or the admin who owns the Source browsing their portal
 *   (see App\Support\TenantContext).
 * - Outside an authenticated context (console commands, tests without
 *   ->actingAs(), seeders) the scope does not filter — that's a trusted context.
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::creating(function ($model) {
            $model->user_id ??= TenantContext::userId();
        });

        static::addGlobalScope('tenant', function ($query) {
            $tenantId = TenantContext::userId();

            if ($tenantId !== null) {
                $query->where($query->getModel()->getTable().'.user_id', $tenantId);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
