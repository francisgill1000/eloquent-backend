<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The single source of truth for every permission the app understands.
 *
 * Consumed by the seeder (to create global spatie Permission rows), the role
 * validation rules (to reject unknown permissions), and the read-only
 * /shop/permissions endpoint (so the admin UI and backend never drift).
 *
 * Each group carries a `module` tag: 'bookings' or 'leads' means the group only
 * belongs to that product; null means shared infrastructure shown for every shop.
 * The roles screen filters by the shop's enabled modules (see forShop()) so a
 * Bookings shop and a Business Hunt shop each see only their own product's
 * permissions — never a mix. This mirrors the module gate the assistant registry
 * and system prompt already use.
 */
class PermissionCatalog
{
    /**
     * module key => ['label' => string, 'module' => ?string, 'permissions' => [name => human label]]
     *
     * @return array<string, array{label: string, module: ?string, permissions: array<string, string>}>
     */
    public static function grouped(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'module' => 'bookings', 'permissions' => [
                'reports.view' => 'View dashboard & reports',
            ]],
            'bookings' => ['label' => 'Bookings', 'module' => 'bookings', 'permissions' => [
                'bookings.view'   => 'View bookings',
                'bookings.create' => 'Create bookings',
                'bookings.update' => 'Update bookings',
                'bookings.delete' => 'Delete bookings',
            ]],
            // In Business Hunt these are leads, a different entity — so Customers
            // belongs to the bookings product only (Francis's call).
            'customers' => ['label' => 'Customers', 'module' => 'bookings', 'permissions' => [
                'customers.view'   => 'View customers',
                'customers.manage' => 'Manage customers',
            ]],
            // Catalog: services + their parent categories share the services.*
            // permissions (the catalog + parent-category routes already enforce them).
            'catalog' => ['label' => 'Services & categories', 'module' => 'bookings', 'permissions' => [
                'services.view'   => 'View services & categories',
                'services.manage' => 'Add, edit & delete services & categories',
            ]],
            'staff' => ['label' => 'Staff', 'module' => 'bookings', 'permissions' => [
                'staff.view'   => 'View staff',
                'staff.manage' => 'Add, edit, delete staff & schedules',
            ]],
            'hours' => ['label' => 'Working hours', 'module' => 'bookings', 'permissions' => [
                'working_hours.view'   => 'View working hours',
                'working_hours.manage' => 'Set working hours & closures',
            ]],
            // Business Hunt (leads module). New in WS2 — Hunt had no per-user
            // permissions before; these gate the Hunt tools + LeadController routes.
            'hunt' => ['label' => 'Business Hunt', 'module' => 'leads', 'permissions' => [
                'leads.view'     => 'View leads, pipeline & Hunt summary',
                'leads.search'   => 'Search businesses (spends credits)',
                'leads.manage'   => 'Save & work leads (status, follow-ups)',
                'leads.purchase' => 'Buy credit packs',
            ]],
            // Shared infrastructure (module = null) — shown for every shop.
            'assistant' => ['label' => 'AI Assistant', 'module' => null, 'permissions' => [
                'assistant.use'    => 'Use the assistant',
                'assistant.manage' => 'Configure the assistant',
            ]],
            'access' => ['label' => 'Users & Roles', 'module' => null, 'permissions' => [
                'users.view'   => 'View users',
                'users.manage' => 'Add, edit & delete users',
                'roles.view'   => 'View roles',
                'roles.manage' => 'Add, edit & delete roles',
            ]],
            'settings' => ['label' => 'Settings', 'module' => null, 'permissions' => [
                'settings.manage' => 'Manage business settings',
            ]],
        ];
    }

    /**
     * The catalog filtered to the groups relevant to a shop's enabled modules,
     * for the roles UI. A group shows when it's shared (module null), the shop is
     * a master, or the shop has the group's module enabled. The `module` tag is
     * stripped so the public shape stays { label, permissions }.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function forShop(?Shop $shop): array
    {
        $out = [];
        foreach (self::grouped() as $key => $group) {
            $module = $group['module'];
            $visible = $module === null
                || ($shop !== null && ($shop->is_master || $shop->hasModule($module)));
            if ($visible) {
                $out[$key] = ['label' => $group['label'], 'permissions' => $group['permissions']];
            }
        }
        return $out;
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
