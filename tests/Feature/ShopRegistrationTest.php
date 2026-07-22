<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function actingOwner(\App\Models\Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = \App\Models\ShopUser::factory()->create(['shop_id' => $shop->id]);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    public function test_registration_requires_master_auth(): void
    {
        $this->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ])->assertStatus(401);
    }

    public function test_master_registers_a_shop_with_email_and_password(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('shakaina@example.com', $response->json('shop.email'));
        $this->assertArrayNotHasKey('pin', $response->json('shop'));

        $shop = Shop::where('name', 'Shakaina Salon')->first();
        $this->assertNotNull($shop);
        $this->assertSame('0554501483', $shop->phone);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('at-least-8-chars', $shop->password));
        $this->assertNotNull($shop->category_confirmed_at); // locked at registration
    }

    public function test_non_master_shop_cannot_register_a_shop(): void
    {
        $notMaster = Shop::factory()->create(['is_master' => false]);
        $token = $notMaster->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina2@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ])->assertStatus(403);
    }

    public function test_registers_with_custom_other_category(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Falcon Tours',
            'phone' => '0554500000',
            'email' => 'falcon@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 0, // "Other"
            'custom_category' => 'Desert Safari Tours',
            'is_verified' => true,
        ]);

        $response->assertCreated();

        $shop = Shop::where('name', 'Falcon Tours')->first();
        $this->assertNotNull($shop);
        $this->assertSame(0, (int) $shop->category_id);
        $this->assertSame('Desert Safari Tours', $shop->custom_category);
        $this->assertSame('Desert Safari Tours', $shop->categoryLabel());
    }

    public function test_other_category_requires_custom_name(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Nameless Other Shop',
            'phone' => '0550000002',
            'email' => 'nameless@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 0, // "Other" but no custom_category
            'is_verified' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('custom_category');
    }

    public function test_rejects_unknown_category(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Bad Cat Shop',
            'phone' => '0550000001',
            'email' => 'badcat@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 99,
            'is_verified' => true,
        ])->assertStatus(422);
    }

    public function test_old_shop_confirms_category_once_then_locked(): void
    {
        $shop = Shop::factory()->create(['category_id' => 1, 'category_confirmed_at' => null]);
        $token = $shop->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        // first confirmation works
        $this->postJson('/api/shop/category', ['category_id' => 9], $headers)
            ->assertOk()
            ->assertJsonPath('shop.category_id', 9);
        $this->assertNotNull($shop->fresh()->category_confirmed_at);

        // second attempt is an idempotent no-op — category stays locked
        $this->postJson('/api/shop/category', ['category_id' => 2], $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Category already set');
        $this->assertSame(9, (int) $shop->fresh()->category_id);
    }

    public function test_phone_can_be_updated_via_shop_update(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/shops/{$shop->id}", ['phone' => '0501112222'])
            ->assertOk();

        $this->assertSame('0501112222', $shop->fresh()->phone);
    }
}
