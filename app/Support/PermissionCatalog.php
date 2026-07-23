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
 * The catalog is organised to mirror the left-side menu **one group per menu
 * item** (Francis: "every left menu should have [a row] in [the] permissions
 * list"). A group's `section` tag says where it sits: null = a top-level menu
 * destination that gets its own row on the roles screen (AI Summary, Home, Chats,
 * Bookings, Customers, Business Hunt, Profile); 'Settings' = a page reached
 * through the Settings container (Insights/reports, Services, Staff, Working
 * hours, AI Assistant config, Users & Roles, business settings) — the roles
 * editor collapses the whole 'Settings' section into a single toggle. Keep this
 * in step with admin/src/lib/nav.ts, admin/src/layout/DesktopSidebar.tsx and
 * MobileLayout.tsx (the perm on each nav item must match the group here).
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
            // ---- One group per top-level left-menu item (section = null) -------
            // Each corresponds 1:1 to a menu row in the sidebar / bottom tabs.
            'summary' => ['label' => 'AI Summary', 'module' => null, 'section' => null, 'permissions' => [
                'summary.view' => 'See the AI summary',
            ]],
            'home' => ['label' => 'Home', 'module' => null, 'section' => null, 'permissions' => [
                'assistant.use' => 'Use the Ask assistant',
            ]],
            'chats' => ['label' => 'Chats', 'module' => null, 'section' => null, 'permissions' => [
                'chats.view' => 'See past assistant chats',
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
                // Widening permission: WITHOUT it a user sees only the leads
                // assigned to them. Removing it from a role is what turns an
                // employee into an agent (see AssignedLeadScope).
                'leads.view_all' => 'See every lead, not just their own',
                'leads.search'   => 'Search businesses (spends credits)',
                'leads.manage'   => 'Save & work leads (status, follow-ups)',
                'leads.assign'   => 'Assign leads to other users',
                'leads.purchase' => 'Buy credit packs',
            ]],
            'profile' => ['label' => 'Profile', 'module' => null, 'section' => null, 'permissions' => [
                'profile.view' => 'View & edit the business profile',
            ]],

            // ---- Everything reached through Settings (section = 'Settings') ----
            // These collapse to a single "Settings" toggle in the roles editor.
            'reports' => ['label' => 'Insights & reports', 'module' => 'bookings', 'section' => 'Settings', 'permissions' => [
                'reports.view' => 'View insights & reports',
            ]],
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
