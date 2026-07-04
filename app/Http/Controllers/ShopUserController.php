<?php

namespace App\Http\Controllers;

use App\Http\Resources\ShopUserResource;
use App\Models\ShopUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Per-shop user (login account) CRUD. Scoped by shop_id; a user belonging to
 * another shop returns 404. A user may hold at most one role.
 */
class ShopUserController extends Controller
{
    public function index(Request $request)
    {
        $shopId = $request->user()->id;

        $users = ShopUser::where('shop_id', $shopId)
            ->with('roles')
            ->orderBy('name')
            ->get();

        return ShopUserResource::collection($users);
    }

    public function store(Request $request)
    {
        $shopId = $request->user()->id;
        $data = $this->validateData($request, $shopId, null);

        $user = ShopUser::create([
            'shop_id' => $shopId,
            'name' => $data['name'],
            'login_pin' => $data['login_pin'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->syncRole($user, $data['role_id'] ?? null, $shopId);

        return (new ShopUserResource($user->load('roles')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ShopUser $shopUser)
    {
        $shopId = $request->user()->id;
        abort_unless($shopUser->shop_id === $shopId, 404);

        $data = $this->validateData($request, $shopId, $shopUser->id);

        $shopUser->name = $data['name'];
        if (!empty($data['login_pin'])) {
            $shopUser->login_pin = $data['login_pin'];
        }
        if (array_key_exists('is_active', $data)) {
            $shopUser->is_active = $data['is_active'];
        }
        $shopUser->save();

        $this->syncRole($shopUser, $data['role_id'] ?? null, $shopId);

        return new ShopUserResource($shopUser->load('roles'));
    }

    public function destroy(Request $request, ShopUser $shopUser)
    {
        $shopId = $request->user()->id;
        abort_unless($shopUser->shop_id === $shopId, 404);

        $acting = current_shop_user();
        abort_if($acting && $acting->id === $shopUser->id, 422, 'You cannot delete yourself.');

        if ($shopUser->hasRole('Owner')) {
            $owners = ShopUser::where('shop_id', $shopId)->get()
                ->filter->hasRole('Owner')
                ->count();
            abort_if($owners <= 1, 422, 'You cannot delete the last owner.');
        }

        $shopUser->delete();

        return response()->json(['message' => 'User deleted']);
    }

    private function syncRole(ShopUser $user, ?int $roleId, int $shopId): void
    {
        if ($roleId === null) {
            $user->syncRoles([]);
            return;
        }

        $role = Role::where('team_id', $shopId)->findOrFail($roleId);
        $user->syncRoles([$role]);
    }

    private function validateData(Request $request, int $shopId, ?int $ignoreId): array
    {
        $pinRule = [
            'string', 'max:10',
            Rule::unique('shop_users', 'login_pin')
                ->where(fn ($q) => $q->where('shop_id', $shopId))
                ->ignore($ignoreId),
        ];

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'login_pin' => $ignoreId
                ? array_merge(['sometimes', 'nullable'], $pinRule)
                : array_merge(['required'], $pinRule),
            'role_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);
    }
}
