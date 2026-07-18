<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsureShopSelfTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    public function test_owner_reaches_own_shop_route(): void
    {
        $shop = Shop::factory()->trialing()->create();
        $token = $this->tokenFor($shop);
        // Guard lets self through to controller validation (422 on empty body), not 403.
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/shops/{$shop->id}/staff", [])
            ->assertStatus(422);
    }

    public function test_foreign_shop_is_forbidden(): void
    {
        $shopA = Shop::factory()->trialing()->create();
        $shopB = Shop::factory()->trialing()->create();
        $token = $this->tokenFor($shopA);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/shops/{$shopB->id}/staff", ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_anonymous_is_unauthenticated(): void
    {
        $shop = Shop::factory()->trialing()->create();
        $this->postJson("/api/shops/{$shop->id}/staff", ['name' => 'X'])
            ->assertStatus(401);
    }
}
