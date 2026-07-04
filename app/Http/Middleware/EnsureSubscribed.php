<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscribed
{
    /**
     * Block shops whose subscription has lapsed. The master account is always
     * exempt. Returns 402 with a machine-readable code the admin SPA uses to
     * redirect to the /subscribe screen.
     */
    public function handle(Request $request, Closure $next)
    {
        $shop = $request->user();

        if ($shop instanceof Shop && $shop->is_master) {
            return $next($request);
        }

        $sub = $shop?->subscription()->first();

        if ($sub && $sub->hasAccess()) {
            return $next($request);
        }

        return response()->json([
            'error' => 'subscription_required',
            'status' => $sub?->status,
            'access_until' => $sub?->access_until,
        ], 402);
    }
}
