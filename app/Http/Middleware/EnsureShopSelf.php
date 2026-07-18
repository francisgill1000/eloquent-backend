<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;

/**
 * Ensures the authenticated Shop owns the {shop} route parameter. Use on any
 * route that mutates or reads shop-scoped admin data addressed by /shops/{shop}/*.
 * Must run after auth:sanctum. The master account bypasses (mirrors EnsureShopModule
 * and EnsureSubscribed).
 */
class EnsureShopSelf
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $param = $request->route('shop');
        $shopId = $param instanceof Shop ? $param->id : (int) $param;

        if ($user instanceof Shop && ($user->is_master || $user->id === $shopId)) {
            return $next($request);
        }

        return response()->json(['message' => 'This action is not permitted.'], 403);
    }
}
