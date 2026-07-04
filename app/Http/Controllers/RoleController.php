<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Per-shop role CRUD. The acting entity ($request->user()) is the Shop, whose
 * id is the spatie team id (already set by SetRbacContext). Every query is
 * scoped by team_id so a shop can only ever see/modify its own roles.
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        $shopId = $request->user()->id;

        $roles = Role::where('team_id', $shopId)
            ->with('permissions')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(Request $request)
    {
        $shopId = $request->user()->id;
        $data = $this->validateData($request, $shopId, null);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'team_id' => $shopId,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);

        return (new RoleResource($role->load('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Role $role)
    {
        $shopId = $request->user()->id;
        abort_unless($role->team_id === $shopId, 404);
        abort_if($role->name === 'Owner', 403, 'The Owner role cannot be modified.');

        $data = $this->validateData($request, $shopId, $role->id);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Request $request, Role $role)
    {
        $shopId = $request->user()->id;
        abort_unless($role->team_id === $shopId, 404);
        abort_if($role->name === 'Owner', 403, 'The Owner role cannot be deleted.');

        $role->delete();

        return response()->json(['message' => 'Role deleted']);
    }

    private function validateData(Request $request, int $shopId, ?int $ignoreId): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q->where('team_id', $shopId))
                    ->ignore($ignoreId),
            ],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(PermissionCatalog::all())],
        ]);
    }
}
