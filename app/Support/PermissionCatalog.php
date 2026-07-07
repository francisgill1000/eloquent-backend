<?php

namespace App\Support;

/**
 * The single source of truth for every permission the app understands.
 *
 * Consumed by the seeder (to create global spatie Permission rows), the role
 * validation rules (to reject unknown permissions), and the read-only
 * /shop/permissions endpoint (so the admin UI and backend never drift).
 */
class PermissionCatalog
{
    /**
     * module key => ['label' => string, 'permissions' => [name => human label]]
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function grouped(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'permissions' => [
                'reports.view' => 'View dashboard & reports',
            ]],
            'bookings' => ['label' => 'Bookings', 'permissions' => [
                'bookings.view'   => 'View bookings',
                'bookings.create' => 'Create bookings',
                'bookings.update' => 'Update bookings',
                'bookings.delete' => 'Delete bookings',
            ]],
            // Services, Staff & Working Hours were removed from the primary nav
            // (they live under Settings, owner-managed), so they're no longer
            // grantable per-role. Their routes stay owner-gated; see
            // EnsurePermission (Owner bypasses) and the seeder's prune step.
            'customers' => ['label' => 'Customers', 'permissions' => [
                'customers.view'   => 'View customers',
                'customers.manage' => 'Manage customers',
            ]],
            'assistant' => ['label' => 'AI Assistant', 'permissions' => [
                'assistant.use'    => 'Use the assistant',
                'assistant.manage' => 'Configure the assistant',
            ]],
            'access' => ['label' => 'Users & Roles', 'permissions' => [
                'users.view'   => 'View users',
                'users.manage' => 'Add, edit & delete users',
                'roles.view'   => 'View roles',
                'roles.manage' => 'Add, edit & delete roles',
            ]],
            'settings' => ['label' => 'Settings', 'permissions' => [
                'settings.manage' => 'Manage business settings',
            ]],
        ];
    }

    /**
     * Flat list of every permission name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::grouped() as $group) {
            foreach (array_keys($group['permissions']) as $perm) {
                $out[] = $perm;
            }
        }
        return $out;
    }
}
