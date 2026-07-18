<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function actingOwner(\App\Models\Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = \App\Models\ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    public function test_requires_authentication(): void
    {
        // No token → the shop is derived from the token, so an unauthenticated
        // request is rejected. shop_id is no longer accepted from the request.
        $this->getJson('/api/shop/reports/ai-summary?from=2026-07-01&to=2026-07-31')
            ->assertStatus(401);
    }

    public function test_returns_low_data_for_empty_shop(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);
        Http::fake();

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/shop/reports/ai-summary?from=' . now()->startOfMonth()->toDateString()
                . '&to=' . now()->endOfMonth()->toDateString())
            ->assertOk();

        $this->assertSame('low_data', $res->json('state'));
        Http::assertNothingSent();
    }
}
