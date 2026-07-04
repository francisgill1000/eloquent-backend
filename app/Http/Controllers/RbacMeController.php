<?php

namespace App\Http\Controllers;

use App\Support\PermissionCatalog;
use App\Support\Rbac;
use Illuminate\Http\Request;

class RbacMeController extends Controller
{
    /**
     * The current session's acting user + effective permissions. Used by the
     * admin app to hydrate its RBAC context on refresh.
     */
    public function me(Request $request)
    {
        $u = current_shop_user();

        return response()->json([
            'user' => $u
                ? ['id' => $u->id, 'name' => $u->name, 'is_active' => (bool) $u->is_active]
                : null,
            'permissions' => Rbac::permissionsFor($u),
            'shop' => $request->user(),
        ]);
    }

    /**
     * Read-only permission catalog, grouped by module, for the roles UI.
     */
    public function permissions()
    {
        return response()->json(['data' => PermissionCatalog::grouped()]);
    }
}
