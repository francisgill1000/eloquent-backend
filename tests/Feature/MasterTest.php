<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterTest extends TestCase
{
    use RefreshDatabase;

    private function authed(Shop $shop): array
    {
        $token = $shop->createToken('test')->plainTextToken;
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_master_sees_all_shops_with_codes_and_pins(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shopA = Shop::factory()->create(['phone' => '0501112222', 'category_id' => 9]);
        Shop::factory()->create();

        $response = $this->getJson('/api/master/shops', $this->authed($master))->assertOk();
        $list = $response->json('data');

        // the master's own account is excluded
        $this->assertCount(2, $list);
        $this->assertNull(collect($list)->firstWhere('id', $master->id));
        $rowA = collect($list)->firstWhere('id', $shopA->id);
        $this->assertSame($shopA->shop_code, $rowA['shop_code']);
        $this->assertSame($shopA->pin, $rowA['pin']);
        $this->assertSame('0501112222', $rowA['phone']);
        $this->assertSame('Salon', $rowA['category']);
        $this->assertFalse($rowA['wa_connected']);
        $this->assertArrayHasKey('bookings_count', $rowA);
    }

    public function test_master_list_includes_status_and_persona(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create(['persona' => 'You are Glow Salon.']);

        $row = collect(
            $this->getJson('/api/master/shops', $this->authed($master))->assertOk()->json('data')
        )->firstWhere('id', $shop->id);

        $this->assertSame('active', $row['status']); // shops are created active
        $this->assertSame('You are Glow Salon.', $row['persona']);
    }

    public function test_master_can_set_persona_and_toggle_status(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create();

        $res = $this->patchJson("/api/master/shops/{$shop->id}", [
            'status' => 'inactive',
            'persona' => 'You are the assistant for Glow Salon. Keep replies short.',
        ], $this->authed($master))->assertOk();

        $this->assertSame('inactive', $res->json('data.status'));
        $this->assertSame('You are the assistant for Glow Salon. Keep replies short.', $res->json('data.persona'));
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'status' => 'inactive']);
    }

    public function test_master_clears_persona_when_blank(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create(['persona' => 'Old persona.']);

        $res = $this->patchJson("/api/master/shops/{$shop->id}", [
            'persona' => '   ',
        ], $this->authed($master))->assertOk();

        $this->assertNull($res->json('data.persona'));
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'persona' => null]);
    }

    public function test_regular_shop_cannot_update_shop(): void
    {
        $shop = Shop::factory()->create(['is_master' => false]);
        $target = Shop::factory()->create();
        $this->patchJson("/api/master/shops/{$target->id}", ['status' => 'inactive'], $this->authed($shop))
            ->assertForbidden();
    }

    public function test_regular_shop_cannot_access_master_endpoint(): void
    {
        $shop = Shop::factory()->create(['is_master' => false]);
        $this->getJson('/api/master/shops', $this->authed($shop))->assertForbidden();
    }

    public function test_guest_cannot_access_master_endpoint(): void
    {
        $this->getJson('/api/master/shops')->assertUnauthorized();
    }
}
