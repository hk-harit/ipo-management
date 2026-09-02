<?php

namespace App\Support;

use App\Models\Source;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves which tenant (User) the current request belongs to.
 *
 * Two different logins can be active in this app:
 *  - `web`    — the admin (a User), who owns rows directly.
 *  - `source` — a Source logging into their own read-only portal. A Source does
 *               not own rows; it belongs to the admin who created it, so it must
 *               be scoped to *that* admin's tenant.
 *
 * Without this, a logged-in Source would fall through the tenant scope entirely
 * (`auth()->check()` is false on the default guard) and see every admin's data.
 */
class TenantContext
{
    /** Guards against the recursion of "resolve the source" -> query Source -> "resolve the source". */
    private static bool $resolvingSource = false;

    /**
     * The User whose rows the current request may touch, or null outside any login.
     *
     * Both guards are named explicitly on purpose. Filament's Authenticate
     * middleware calls Auth::shouldUse() with the current panel's guard, so on a
     * portal request the *default* guard is 'source' and a bare auth()->id()
     * would hand back a Source id — which would then be matched against user_id
     * and silently scope everything to nothing (or, worse, to the wrong admin).
     */
    public static function userId(): ?int
    {
        if ($source = static::source()) {
            return $source->user_id;
        }

        return Auth::guard('web')->id();
    }

    /** The Source logged into the portal, if this request is a portal request. */
    public static function source(): ?Source
    {
        if (static::$resolvingSource) {
            return null;
        }

        static::$resolvingSource = true;

        try {
            $user = Auth::guard('source')->user();
        } finally {
            static::$resolvingSource = false;
        }

        return $user instanceof Source ? $user : null;
    }

    /** True when the current request is a source browsing their own portal. */
    public static function isSourcePortal(): bool
    {
        return static::source() !== null;
    }
}
