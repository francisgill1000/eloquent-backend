<?php

namespace App\Http\Middleware;

use App\Support\Rbac;
use Closure;
use Illuminate\Http\Request;

/**
 * Route guard: 403s the request unless the acting ShopUser holds the given
 * permission. Owner and untagged sessions bypass. Requires SetRbacContext to
 * have run first (registered on the api group).
 *
 * Usage: ->middleware('can.perm:bookings.delete')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!Rbac::userCan(current_shop_user(), $permission)) {
            return response()->json([
                'message' => 'This action is not permitted for your role.',
            ], 403);
        }

        return $next($request);
    }
}
