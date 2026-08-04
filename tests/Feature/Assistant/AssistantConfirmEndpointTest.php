<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use App\Models\Conversation;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\Staff;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantConfirmEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        return $shop;
    }

    private function pending(Shop $shop, array $attrs = []): AssistantPendingAction
    {
        return AssistantPendingAction::create(array_merge([
            'shop_id' => $shop->id, 'tool' => 'create_staff', 'input' => ['name' => 'Jhon'],
            'summary' => 'Add staff member "Jhon"', 'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ], $attrs));
    }

    public function test_confirming_writes_from_the_stored_input(): void
    {
        $shop = $this->shop('7420');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('reply_text', '✅ Add staff member "Jhon"');

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
        $this->assertNotNull($row->fresh()->resolved_at);
    }

    public function test_the_client_cannot_smuggle_different_values(): void
    {
        $shop = $this->shop('7421');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        // Extra keys in the request body must be ignored: only `id` is read.
        $this->postJson('/api/shop/assistant/confirm', [
            'id' => $row->id, 'tool' => 'delete_user', 'input' => ['name' => 'Mallory'],
        ])->assertCreated();

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
    }

    public function test_a_destructive_row_writes_because_the_user_confirmed_it(): void
    {
        $shop = $this->shop('7422');
        Sanctum::actingAs($shop, ['*']);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $row = $this->pending($shop, [
            'tool' => 'delete_staff', 'input' => ['name' => 'Ali'],
            'summary' => 'Delete staff member "Ali"', 'destructive' => true,
        ]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_another_shops_row_is_not_found(): void
    {
        $mine = $this->shop('7423');
        $theirs = $this->shop('7424');
        Sanctum::actingAs($mine, ['*']);
        $row = $this->pending($theirs);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertNotFound();
        $this->assertSame(0, Staff::where('shop_id', $theirs->id)->count());
    }

    public function test_a_resolved_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7425');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['resolved_at' => now()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_an_expired_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7426');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    /**
     * The row must be claimed atomically BEFORE the tool runs. A retried POST
     * (flaky mobile connection, proxy retry, double tap) previously passed the
     * liveness check twice and wrote twice — two staff rows from one card.
     */
    public function test_the_same_id_cannot_be_applied_twice(): void
    {
        $shop = $this->shop('7428');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', true);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);

        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
    }

    /**
     * The atomicity itself: by the time the tool runs, the row must ALREADY be
     * claimed, so a second request that arrives mid-execution loses the race
     * instead of executing alongside it. Checking the row from inside the tool
     * call is the deterministic stand-in for two concurrent POSTs.
     */
    public function test_the_row_is_claimed_before_the_tool_executes(): void
    {
        $shop = $this->shop('7429');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        $real = app(\App\Services\Assistant\AssistantToolRegistry::class);
        $liveDuringExecute = null;
        $spy = \Mockery::mock(\App\Services\Assistant\AssistantToolRegistry::class)->makePartial();
        $spy->shouldReceive('execute')->andReturnUsing(
            function (...$args) use ($real, $row, &$liveDuringExecute) {
                // A concurrent request would run its claim UPDATE right here.
                $liveDuringExecute = AssistantPendingAction::whereKey($row->id)
                    ->whereNull('resolved_at')->where('expires_at', '>', now())->exists();

                return $real->execute(...$args);
            },
        );
        $this->app->instance(\App\Services\Assistant\AssistantToolRegistry::class, $spy);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertFalse($liveDuringExecute, 'the pending row was still claimable while its tool was running');
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
    }

    /**
     * A permission-limited actor, exactly as MenuPermissionIsolationTest builds
     * one: a role holding only $perms, a ShopUser wearing it, and a token tagged
     * with that user so rbac.context resolves it into current_shop_user().
     *
     * @param array<int, string> $perms
     */
    private function staffHeaders(Shop $shop, array $perms): array
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Staff-' . uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $user->assignRole($role);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return ['Authorization' => "Bearer {$token->plainTextToken}", 'Accept' => 'application/json'];
    }

    private function rbacShop(): Shop
    {
        (new PermissionSeeder())->run();

        return Shop::factory()->trialing()->create(['modules' => ['bookings']]);
    }

    /**
     * The design promises "RBAC and module gating apply exactly as on the model
     * path" at confirm time. A card is not a permission: an actor who cannot
     * manage staff must not be able to create one by tapping Confirm on a
     * preview a more privileged turn left behind.
     */
    public function test_confirming_without_the_tools_permission_writes_nothing(): void
    {
        $shop = $this->rbacShop();
        $row = $this->pending($shop);

        $this->withHeaders($this->staffHeaders($shop, ['assistant.use', 'staff.view']))
            ->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', false);

        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
        // Spent either way, so a refusal cannot be retried into a different outcome.
        $this->assertNotNull($row->fresh()->resolved_at);
    }

    public function test_confirming_with_the_tools_permission_writes(): void
    {
        $shop = $this->rbacShop();
        $row = $this->pending($shop);

        $this->withHeaders($this->staffHeaders($shop, ['assistant.use', 'staff.manage']))
            ->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', true);

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
    }

    /** The route itself takes assistant.use, exactly like /text and /voice. */
    public function test_the_confirm_route_is_gated_by_assistant_use(): void
    {
        $shop = $this->rbacShop();
        $row = $this->pending($shop);

        // staff.manage would let the tool run — the middleware must stop the
        // request before the controller is ever reached.
        $this->withHeaders($this->staffHeaders($shop, ['staff.manage']))
            ->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertStatus(403);

        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
        $this->assertNull($row->fresh()->resolved_at);
    }

    public function test_the_confirmation_line_is_appended_to_the_thread(): void
    {
        $shop = $this->shop('7427');
        Sanctum::actingAs($shop, ['*']);
        $conversation = Conversation::create(['shop_id' => $shop->id, 'title' => 'setup', 'source' => 'owner']);
        $row = $this->pending($shop, ['conversation_id' => $conversation->id]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(
            ['✅ Add staff member "Jhon"'],
            $conversation->messages()->where('role', 'assistant')->pluck('content')->all(),
        );
    }
}
