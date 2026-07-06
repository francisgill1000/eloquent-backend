<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;

class EnsureShopModule
{
    /**
     * Block shops that don't have the given product module enabled. The master
     * account is always exempt (mirrors EnsureSubscribed). Returns 403 with a
     * machine-readable code the admin SPA can react to.
     */
    public function handle(Request $request, Closure $next, string $module)
    {
        $shop = $request->user();

        if ($shop instanceof Shop && ($shop->is_master || $shop->hasModule($module))) {
            return $next($request);
        }

        return response()->json([
            'error' => 'module_not_enabled',
            'module' => $module,
        ], 403);
    }
}
