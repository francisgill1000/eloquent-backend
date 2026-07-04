<?php
namespace App\Services\Assistant\Modules;

use App\Models\ShopUser;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Role;

/**
 * Owner-assistant access-control tools: users and roles (spatie, per-shop teams
 * keyed by shop id). Full per-permission role editing by voice; the assistant
 * reads permission names from list_permissions to speak them naturally.
 */
class AccessTools extends MutatingTool
{
    protected function permissions(): array
    {
        return [
            'list_users'       => 'users.view',
            'create_user'      => 'users.manage',
            'update_user'      => 'users.manage',
            'delete_user'      => 'users.manage',
            'list_roles'       => 'roles.view',
            'list_permissions' => 'roles.view',
            'create_role'      => 'roles.manage',
            'update_role'      => 'roles.manage',
            'delete_role'      => 'roles.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'list_users'       => $this->listUsers($call),
            'create_user'      => $this->createUser($call),
            'update_user'      => $this->updateUser($call),
            'delete_user'      => $this->deleteUser($call),
            'list_roles'       => $this->listRoles($call),
            'list_permissions' => $this->listPermissions($call),
            'create_role'      => $this->createRole($call),
            'update_role'      => $this->updateRole($call),
            'delete_role'      => $this->deleteRole($call),
            default            => ['error' => 'unknown_tool'],
        };
    }

    // ---- Users -------------------------------------------------------------

    private function resolveUser(ToolCall $call): array|ShopUser
    {
        $name = trim((string) $call->get('name'));
        if ($name === '') return $this->notFound('user');
        $matches = ShopUser::where('shop_id', $call->shop->id)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])->get();
        if ($matches->count() === 0) return $this->notFound('user');
        if ($matches->count() > 1) return $this->ambiguous($matches->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all());
        return $matches->first();
    }

    private function resolveRoleByName(ToolCall $call, string $name): ?Role
    {
        if (trim($name) === '') return null;
        return Role::where('team_id', $call->shop->id)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
    }

    private function listUsers(ToolCall $call): array
    {
        $users = ShopUser::where('shop_id', $call->shop->id)->with('roles')->get();
        return ['count' => $users->count(), 'users' => $users->map(fn ($u) => [
            'name' => $u->name, 'active' => (bool) $u->is_active, 'role' => $u->roles->pluck('name')->first(),
        ])->all()];
    }

    private function createUser(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: function () use ($call) {
                if (! $call->get('name') || ! $call->get('login_pin')) {
                    return ['error' => 'not_found', 'what' => 'missing_fields'];
                }
                $exists = ShopUser::where('shop_id', $call->shop->id)->where('login_pin', $call->get('login_pin'))->exists();
                return $exists ? ['error' => 'not_found', 'what' => 'pin_taken'] : ['ok' => true];
            },
            describe: fn () => ["Add user \"{$call->get('name')}\"" . ($call->get('role') ? " as {$call->get('role')}" : ''), ['user' => "new: {$call->get('name')}"]],
            write: function () use ($call) {
                $user = ShopUser::create([
                    'shop_id' => $call->shop->id, 'name' => $call->get('name'),
                    'login_pin' => $call->get('login_pin'), 'is_active' => true,
                ]);
                if ($call->get('role') && ($role = $this->resolveRoleByName($call, (string) $call->get('role')))) {
                    $user->syncRoles([$role]);
                }
                return ['id' => $user->id, 'name' => $user->name];
            },
        );
    }

    private function updateUser(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveUser($call),
            describe: function ($u) use ($call) {
                $changes = [];
                if ($call->get('new_name')) $changes['name'] = "{$u->name} → {$call->get('new_name')}";
                if ($call->get('is_active') !== null) $changes['active'] = $call->get('is_active') ? 'active' : 'inactive';
                if ($call->get('login_pin')) $changes['pin'] = 'reset';
                if ($call->get('role')) $changes['role'] = $call->get('role');
                return ["Update user \"{$u->name}\"", $changes ?: ['user' => $u->name]];
            },
            write: function ($u) use ($call) {
                if ($call->get('new_name')) $u->name = $call->get('new_name');
                if ($call->get('is_active') !== null) $u->is_active = (bool) $call->get('is_active');
                if ($call->get('login_pin')) $u->login_pin = $call->get('login_pin');
                $u->save();
                if ($call->get('role')) {
                    $role = $this->resolveRoleByName($call, (string) $call->get('role'));
                    $u->syncRoles($role ? [$role] : []);
                }
                return ['id' => $u->id];
            },
        );
    }

    private function deleteUser(ToolCall $call): array
    {
        $target = $this->resolveUser($call);
        if (is_array($target)) {
            return $target; // notFound / ambiguous
        }
        $acting = current_shop_user();
        if ($acting && $acting->id === $target->id) {
            return ['error' => 'cannot_delete_self'];
        }
        if ($target->hasRole('Owner')) {
            $owners = ShopUser::where('shop_id', $call->shop->id)->get()->filter->hasRole('Owner')->count();
            if ($owners <= 1) {
                return ['error' => 'cannot_delete_last_owner'];
            }
        }
        return $this->gate(
            $call,
            resolve: fn () => $target,
            describe: fn ($u) => ["Delete user \"{$u->name}\"", ['user' => "{$u->name} removed"]],
            write: function ($u) {
                $id = $u->id;
                $u->delete();
                return ['id' => $id];
            },
        );
    }

    // ---- Roles -------------------------------------------------------------

    private function resolveRole(ToolCall $call): array|Role
    {
        $role = $this->resolveRoleByName($call, (string) $call->get('name'));
        return $role ?: $this->notFound('role');
    }

    private function validPermissions(array $names): array
    {
        $all = PermissionCatalog::all();
        return array_values(array_filter($names, fn ($n) => in_array($n, $all, true)));
    }

    private function listRoles(ToolCall $call): array
    {
        $roles = Role::where('team_id', $call->shop->id)->with('permissions')->get();
        return ['count' => $roles->count(), 'roles' => $roles->map(fn ($r) => [
            'name' => $r->name, 'permissions' => $r->permissions->pluck('name')->all(),
        ])->all()];
    }

    private function listPermissions(ToolCall $call): array
    {
        $out = [];
        foreach (PermissionCatalog::grouped() as $group) {
            foreach ($group['permissions'] as $name => $label) {
                $out[] = ['name' => $name, 'label' => $label];
            }
        }
        return ['permissions' => $out];
    }

    private function createRole(ToolCall $call): array
    {
        $perms = $this->validPermissions((array) $call->get('permissions', []));
        return $this->gate(
            $call,
            resolve: function () use ($call) {
                if (! $call->get('name')) return ['error' => 'not_found', 'what' => 'missing_name'];
                if ($this->resolveRoleByName($call, (string) $call->get('name'))) return ['error' => 'not_found', 'what' => 'role_exists'];
                return ['ok' => true];
            },
            describe: fn () => ["Create role \"{$call->get('name')}\" with " . (count($perms) ? implode(', ', $perms) : 'no permissions'), ['role' => "new: {$call->get('name')}"]],
            write: function () use ($call, $perms) {
                $role = Role::create(['name' => $call->get('name'), 'guard_name' => 'web', 'team_id' => $call->shop->id]);
                $role->syncPermissions($perms);
                return ['name' => $role->name, 'permissions' => $perms];
            },
        );
    }

    private function updateRole(ToolCall $call): array
    {
        $target = $this->resolveRole($call);
        if (is_array($target)) return $target;
        if ($target->name === 'Owner') return ['error' => 'owner_role_locked'];
        $perms = $call->get('permissions') !== null ? $this->validPermissions((array) $call->get('permissions')) : null;
        return $this->gate(
            $call,
            resolve: fn () => $target,
            describe: function ($r) use ($call, $perms) {
                $changes = [];
                if ($call->get('new_name')) $changes['name'] = "{$r->name} → {$call->get('new_name')}";
                if ($perms !== null) $changes['permissions'] = implode(', ', $perms) ?: 'none';
                return ["Update role \"{$r->name}\"", $changes ?: ['role' => $r->name]];
            },
            write: function ($r) use ($call, $perms) {
                if ($call->get('new_name')) $r->update(['name' => $call->get('new_name')]);
                if ($perms !== null) $r->syncPermissions($perms);
                return ['name' => $r->name];
            },
        );
    }

    private function deleteRole(ToolCall $call): array
    {
        $target = $this->resolveRole($call);
        if (is_array($target)) return $target;
        if ($target->name === 'Owner') return ['error' => 'owner_role_locked'];
        return $this->gate(
            $call,
            resolve: fn () => $target,
            describe: fn ($r) => ["Delete role \"{$r->name}\"", ['role' => "{$r->name} removed"]],
            write: function ($r) {
                $name = $r->name;
                $r->delete();
                return ['name' => $name];
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'list_users', 'description' => 'List login users and their role.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_user', 'description' => 'Add a login user. Requires name and login_pin; optional role name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'login_pin' => ['type' => 'string', 'description' => 'Numeric PIN, up to 10 chars'],
                'role' => ['type' => 'string', 'description' => 'Role name to assign'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'login_pin']]],
            ['name' => 'update_user', 'description' => 'Update a user: rename, activate/deactivate, reset PIN (login_pin), or change role. Identify by name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'Current name'],
                'new_name' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
                'login_pin' => ['type' => 'string', 'description' => 'New PIN'],
                'role' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'delete_user', 'description' => 'Delete a login user by name. Cannot delete yourself or the last owner. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'list_roles', 'description' => 'List roles and their permissions.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_permissions', 'description' => 'List every permission name and its plain-language label (use before creating/editing a role).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_role', 'description' => 'Create a role with a list of permission names. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Permission names from list_permissions'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'update_role', 'description' => 'Rename a role and/or replace its permissions. Identify by name. The Owner role is locked. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'Current name'],
                'new_name' => ['type' => 'string'],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'delete_role', 'description' => 'Delete a role by name (Owner is locked). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
        ];
    }
}
