<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RBAC audit follow-up: every left-menu destination of a Business Hunt shop must
 * be independently grantable AND independently enforced on the API. The audit
 * found three permissions (chats.view, profile.view, assistant.manage) that the
 * roles screen offered but no route checked, plus settings.manage which was only
 * honoured by the assistant tool. These tests pin all of that down.
 *
 * The isolation test is the one that answers the original question: create one
 * user per menu item and confirm each can reach only its own section.
 */
class MenuPermissionIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int,string> $perms */
    private function staffToken(Shop $shop, array $perms): string
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    private function hdrs(string $token): array
    {
        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    /**
     * A Business Hunt shop. `trialing` matters for the routes that still sit
     * behind subscription.active (the Ask assistant and its conversations) —
     * without it those return 402 before any permission check runs, which would
     * mask what these tests are actually asserting.
     */
    private function huntShop(): Shop
    {
        (new PermissionSeeder())->run();

        return Shop::factory()->trialing()->create(['modules' => ['leads']]);
    }

    // ---------------------------------------------------------------------
    // The permissions the audit found unenforced
    // ---------------------------------------------------------------------

    public function test_chats_view_gates_the_conversation_routes(): void
    {
        $shop = $this->huntShop();

        // Transcripts carry lead names, credit balances and won-deal revenue, so
        // hiding the Chats menu client-side was never enough.
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['leads.view'])))
            ->getJson('/api/shop/assistant/conversations')
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['chats.view'])))
            ->getJson('/api/shop/assistant/conversations')
            ->assertOk();
    }

    public function test_assistant_manage_gates_the_persona_routes(): void
    {
        $shop = $this->huntShop();

        $token = $this->staffToken($shop, ['leads.view']);
        $this->withHeaders($this->hdrs($token))->getJson('/api/shop/persona')->assertStatus(403);
        $this->withHeaders($this->hdrs($token))
            ->putJson('/api/shop/persona', ['persona' => 'pwned'])
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['assistant.manage'])))
            ->getJson('/api/shop/persona')
            ->assertOk();
    }

    public function test_profile_view_gates_business_profile_writes(): void
    {
        $shop = $this->huntShop();

        $this->withHeaders($this->hdrs($this->staffToken($shop, ['leads.view'])))
            ->putJson("/api/shops/{$shop->id}", ['name' => 'Renamed By Staff'])
            ->assertStatus(403);

        $this->assertSame($shop->name, $shop->fresh()->name);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['profile.view'])))
            ->putJson("/api/shops/{$shop->id}", ['name' => 'Renamed By Owner'])
            ->assertOk();

        $this->assertSame('Renamed By Owner', $shop->fresh()->name);
    }

    public function test_settings_manage_does_not_unlock_profile_fields(): void
    {
        $shop = $this->huntShop();

        // The two menus are separate grants in both directions: settings.manage
        // is not a backdoor into renaming the business.
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['settings.manage'])))
            ->putJson("/api/shops/{$shop->id}", ['name' => 'Renamed By Settings User'])
            ->assertStatus(403);

        $this->assertSame($shop->name, $shop->fresh()->name);
    }

    public function test_settings_manage_gates_notification_settings_writes(): void
    {
        $shop = $this->huntShop();
        // Read through fresh(): the factory's in-memory model has no value for
        // this column, so only the DB row shows the real default.
        $before = (bool) $shop->fresh()->booking_reminders_enabled;

        // Notification fields belong to the Settings menu, not the Profile menu —
        // profile.view alone must not unlock them.
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['profile.view'])))
            ->putJson("/api/shops/{$shop->id}", ['booking_reminders_enabled' => ! $before])
            ->assertStatus(403);

        $this->assertSame($before, (bool) $shop->fresh()->booking_reminders_enabled);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['settings.manage'])))
            ->putJson("/api/shops/{$shop->id}", [
                'booking_reminders_enabled' => ! $before,
                'booking_reminder_template' => 'Hi {name}, see you {date}.',
                'google_review_url'         => 'https://g.page/r/demo/review',
            ])
            ->assertOk();

        // Previously these were absent from UpdateShopRequest::rules(), so
        // validated() stripped them and Save reported success while persisting
        // nothing. Assert they actually land now.
        $fresh = $shop->fresh();
        $this->assertSame(! $before, (bool) $fresh->booking_reminders_enabled);
        $this->assertSame('Hi {name}, see you {date}.', $fresh->booking_reminder_template);
        $this->assertSame('https://g.page/r/demo/review', $fresh->google_review_url);
    }

    public function test_a_profile_save_does_not_wipe_notification_settings(): void
    {
        $shop = $this->huntShop();
        $shop->forceFill([
            'booking_reminder_template' => 'Keep me',
            'waitlist_notify_enabled'   => false,
        ])->save();

        // The Profile page PUTs only its own fields; `sometimes` on the
        // notification rules must keep the untouched ones out of validated().
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['profile.view'])))
            ->putJson("/api/shops/{$shop->id}", ['name' => 'Renamed'])
            ->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('Keep me', $fresh->booking_reminder_template);
        $this->assertFalse((bool) $fresh->waitlist_notify_enabled);
    }

    public function test_an_invalid_google_review_url_is_rejected(): void
    {
        $shop = $this->huntShop();

        $this->withHeaders($this->hdrs($this->staffToken($shop, ['settings.manage'])))
            ->putJson("/api/shops/{$shop->id}", ['google_review_url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('google_review_url');
    }

    public function test_working_hours_permission_is_still_enforced_separately(): void
    {
        $shop = $this->huntShop();

        // Regression guard: adding the profile/settings checks must not have made
        // profile.view a backdoor into the working-hours sync.
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['profile.view'])))
            ->putJson("/api/shops/{$shop->id}", [
                'working_hours' => [
                    ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ],
            ])
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // Hunt write permissions the audit found untested
    // ---------------------------------------------------------------------

    public function test_leads_manage_gates_pipeline_writes(): void
    {
        $shop = $this->huntShop();
        $lead = Lead::factory()->create(['shop_id' => $shop->id]);

        $viewer = $this->staffToken($shop, ['leads.view']);
        $this->withHeaders($this->hdrs($viewer))
            ->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertStatus(403);
        $this->withHeaders($this->hdrs($viewer))
            ->postJson("/api/shop/leads/{$lead->id}/followup")
            ->assertStatus(403);

        $this->assertSame($lead->status, $lead->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['leads.view', 'leads.manage'])))
            ->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();
    }

    public function test_leads_purchase_gates_buying_credit_packs(): void
    {
        $shop = $this->huntShop();

        // The money-spending route. Rejected by middleware before the controller,
        // so no Ziina call is made.
        $this->withHeaders($this->hdrs($this->staffToken($shop, ['leads.view', 'leads.manage'])))
            ->postJson('/api/shop/leads/purchase', ['pack_id' => 1])
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // Role grants must stay inside the shop's own catalog
    // ---------------------------------------------------------------------

    public function test_a_hunt_shop_cannot_grant_bookings_permissions_to_a_role(): void
    {
        $shop = $this->huntShop();
        $token = $this->staffToken($shop, ['roles.view', 'roles.manage']);

        $this->withHeaders($this->hdrs($token))
            ->postJson('/api/shop/roles', [
                'name' => 'Sneaky',
                'permissions' => ['leads.view', 'bookings.delete'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.1');
    }

    public function test_a_hunt_shop_can_still_grant_its_own_permissions(): void
    {
        $shop = $this->huntShop();
        $token = $this->staffToken($shop, ['roles.view', 'roles.manage']);

        $this->withHeaders($this->hdrs($token))
            ->postJson('/api/shop/roles', [
                'name' => 'Hunter',
                'permissions' => ['leads.view', 'leads.manage', 'settings.manage'],
            ])
            ->assertStatus(201);
    }

    // ---------------------------------------------------------------------
    // The headline question: one user per left-menu item
    // ---------------------------------------------------------------------

    /**
     * Each left-menu destination of a Hunt shop, with the permission that should
     * unlock it and a GET that only that permission should reach.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function menuProvider(): array
    {
        return [
            'AI Summary'    => ['summary.view',     '/api/shop/reports/ai-summary?from=2026-07-01&to=2026-07-24'],
            'Chats'         => ['chats.view',       '/api/shop/assistant/conversations'],
            'Business Hunt' => ['leads.view',       '/api/shop/leads'],
            'AI Assistant'  => ['assistant.manage', '/api/shop/persona'],
            'Users & Roles' => ['roles.view',       '/api/shop/roles'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('menuProvider')]
    public function test_one_user_per_left_menu_reaches_only_its_own_section(string $perm, string $url): void
    {
        $shop = $this->huntShop();

        // Granted: reachable.
        $this->withHeaders($this->hdrs($this->staffToken($shop, [$perm])))
            ->getJson($url)
            ->assertOk();

        // Every OTHER menu's permission: denied on this URL.
        foreach (self::menuProvider() as [$otherPerm, $_]) {
            if ($otherPerm === $perm) {
                continue;
            }
            $this->app['auth']->forgetGuards();
            $this->withHeaders($this->hdrs($this->staffToken($shop, [$otherPerm])))
                ->getJson($url)
                ->assertStatus(403);
        }
    }
}
