<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopUserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_has_many_shop_users_and_pin_is_hidden(): void
    {
        $shop = Shop::factory()->create();
        $user = ShopUser::factory()->create([
            'shop_id' => $shop->id,
            'name' => 'Alice',
            'login_pin' => '4321',
        ]);

        $this->assertTrue($shop->shopUsers->contains($user));
        $this->assertArrayNotHasKey('login_pin', $user->toArray());
        $this->assertSame('Alice', $user->name);
    }

    public function test_login_pin_is_unique_per_shop(): void
    {
        $shop = Shop::factory()->create();
        ShopUser::factory()->create(['shop_id' => $shop->id, 'login_pin' => '1111']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ShopUser::factory()->create(['shop_id' => $shop->id, 'login_pin' => '1111']);
    }

    public function test_same_pin_allowed_in_different_shops(): void
    {
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();

        ShopUser::factory()->create(['shop_id' => $a->id, 'login_pin' => '9999']);
        $second = ShopUser::factory()->create(['shop_id' => $b->id, 'login_pin' => '9999']);

        $this->assertNotNull($second->id);
    }
}
