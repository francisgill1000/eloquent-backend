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
 *
 * Each group also carries a `section` tag that mirrors the left-nav hierarchy so
 * the roles screen can nest permissions the way the app is actually navigated:
 * null = a top-level menu destination (Bookings, Customers, Business Hunt, the
 * Home assistant, the dashboard); 'Settings' = a page reached through the Settings
 * container (Services, Staff, Working hours, AI Assistant config, Users & Roles,
 * business settings). Keep this in step with admin/src/lib/nav.ts.
 */
class PermissionCatalog
{
    /**
     * module key => ['label' => string, 'module' => ?string, 'section' => ?string, 'permissions' => [name => human label]]
     *
     * @return array<string, array{label: string, module: ?string, section: ?string, permissions: array<string, string>}>
     */
    public static function grouped(): array
    {
        return [
            // ---- Top-level menu destinations (section = null) ------------------
            'dashboard' => ['label' => 'Dashboard', 'module' => 'bookings', 'section' => null, 'permissions' => [
                'reports.view' => 'View dashboard & reports',
            ]],
            'bookings' => ['label' => 'Bookings', 'module' => 'bookings', 'section' => null, 'permissions' => [
                'bookings.view'   => 'View bookings',
                'bookings.create' => 'Create bookings',
                'bookings.update' => 'Update bookings',
                'bookings.delete' => 'Delete bookings',
            ]],
            // In Business Hunt these are leads, a different entity — so Customers
            // belongs to the bookings product only (Francis's call).
            'customers' => ['label' => 'Customers', 'module' => 'bookings', 'section' => null, 'permissions' => [
                'customers.view'   => 'View customers',
                'customers.manage' => 'Manage customers',
            ]],
            // Business Hunt (leads module). New in WS2 — Hunt had no per-user
            // permissions before; these gate the Hunt tools + LeadController routes.
            'hunt' => ['label' => 'Business Hunt', 'module' => 'leads', 'section' => null, 'permissions' => [
                'leads.view'     => 'View leads, pipeline & Hunt summary',
                'leads.search'   => 'Search businesses (spends credits)',
                'leads.manage'   => 'Save & work leads (status, follow-ups)',
                'leads.purchase' => 'Buy credit packs',
            ]],
            // The assistant powers the Home page — a top-level destination — so its
            // "use" permission lives up here. Its "configure" permission is a
            // Settings page and lives in the Settings section below.
            'assistant' => ['label' => 'Assistant', 'module' => null, 'section' => null, 'permissions' => [
                'assistant.use' => 'Use the assistant',
            ]],

            // ---- Reached through Settings (section = 'Settings') ---------------
            // Catalog: services + their parent categories share the services.*
            // permissions (the catalog + parent-category routes already enforce them).
            'catalog' => ['label' => 'Services & categories', 'module' => 'bookings', 'section' => 'Settings', 'permissions' => [
                'services.view'   => 'View services & categories',
                'services.manage' => 'Add, edit & delete services & categories',
            ]],
            'staff' => ['label' => 'Staff', 'module' => 'bookings', 'section' => 'Settings', 'permissions' => [
                'staff.view'   => 'View staff',
                'staff.manage' => 'Add, edit, delete staff & schedules',
            ]],
            'hours' => ['label' => 'Working hours', 'module' => 'bookings', 'section' => 'Settings', 'permissions' => [
                'working_hours.view'   => 'View working hours',
                'working_hours.manage' => 'Set working hours & closures',
            ]],
            'assistant_config' => ['label' => 'AI Assistant', 'module' => null, 'section' => 'Settings', 'permissions' => [
                'assistant.manage' => 'Configure the assistant',
            ]],
            'access' => ['label' => 'Users & Roles', 'module' => null, 'section' => 'Settings', 'permissions' => [
                'users.view'   => 'View users',
                'users.manage' => 'Add, edit & delete users',
                'roles.view'   => 'View roles',
                'roles.manage' => 'Add, edit & delete roles',
            ]],
            'settings' => ['label' => 'Business settings', 'module' => null, 'section' => 'Settings', 'permissions' => [
                'settings.manage' => 'Manage business settings',
            ]],
        ];
    }

    /**
     * The catalog filtered to the groups relevant to a shop's enabled modules,
     * for the roles UI. A group shows when it's shared (module null), the shop is
     * a master, or the shop has the group's module enabled. The `module` tag is
     * stripped so the public shape stays { label, section, permissions } — the UI
     * uses `section` to nest Settings pages under a Settings header.
     *
     * @return array<string, array{label: string, section: ?string, permissions: array<string, string>}>
     */
    public static function forShop(?Shop $shop): array
    {
        $out = [];
        foreach (self::grouped() as $key => $group) {
            $module = $group['module'];
            $visible = $module === null
                || ($shop !== null && ($shop->is_master || $shop->hasModule($module)));
            if ($visible) {
                $out[$key] = [
                    'label'       => $group['label'],
                    'section'     => $group['section'],
                    'permissions' => $group['permissions'],
                ];
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
