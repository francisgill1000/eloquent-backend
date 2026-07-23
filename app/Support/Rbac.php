<?php

namespace App\Support;

use App\Models\ShopUser;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Central permission logic. Owner role and untagged (null) sessions are
 * treated as all-allowed for backward compatibility with pre-RBAC tokens.
 */
class Rbac
{
    public const WILDCARD = '*';
    public const OWNER_ROLE = 'Owner';

    public static function isOwner(?ShopUser $user): bool
    {
        return $user !== null && $user->hasRole(self::OWNER_ROLE);
    }

    /**
     * Flattened effective permission names for a user, or ['*'] for
     * owner/untagged sessions.
     *
     * @return array<int, string>
     */
    public static function permissionsFor(?ShopUser $user): array
    {
        if ($user === null || self::isOwner($user)) {
            return [self::WILDCARD];
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    public static function userCan(?ShopUser $user, string $permission): bool
    {
        // Untagged session (null) is owner-equivalent for backward compat.
        if ($user === null || self::isOwner($user)) {
            return true;
        }

        // A route may still gate on a permission that's been removed from the
        // catalog (e.g. Services/Staff/Working Hours). Spatie throws for an
        // unknown permission — treat that as "denied" so the route 403s
        // cleanly instead of 500-ing.
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * May this user see every lead in the shop, or only the ones assigned to
     * them? Delegates to userCan so owners and untagged (legacy) sessions stay
     * all-allowed, and an unseeded permission fails closed to "own leads only".
     */
    public static function seesAllLeads(?ShopUser $user): bool
    {
        return self::userCan($user, 'leads.view_all');
    }
}
