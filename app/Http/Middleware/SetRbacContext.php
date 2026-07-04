<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\CurrentShopUser;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the acting ShopUser from the Sanctum token and sets the spatie
 * team scope to the current shop, so all role/permission checks and queries
 * downstream are automatically tenant-scoped.
 */
class SetRbacContext
{
    public function handle(Request $request, Closure $next)
    {
        CurrentShopUser::set(null);

        $auth = $request->user();

        if ($auth instanceof Shop) {
            // Team scope for spatie: roles/pivots are keyed by shop id.
            setPermissionsTeamId($auth->id);

            $token = $auth->currentAccessToken();
            $shopUserId = $token ? ($token->shop_user_id ?? null) : null;

            if ($shopUserId) {
                $shopUser = ShopUser::with('roles')->find($shopUserId);
                if ($shopUser && $shopUser->shop_id === $auth->id) {
                    CurrentShopUser::set($shopUser);
                }
            }
        }

        return $next($request);
    }
}
