<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ShopUserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_logs_in_with_email_and_password(): void
    {
        $shop = Shop::factory()->create();
        $staff = ShopUser::factory()->create([
            'shop_id' => $shop->id,
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'correct-horse',
        ]);

        $res = $this->postJson('/api/shops/login', [
            'email' => 'bob@example.com',
            'password' => 'correct-horse',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('user.id', $staff->id)
            ->assertJsonPath('user.name', 'Bob')
            ->assertJsonPath('shop.id', $shop->id);

        $this->assertNotEmpty($res->json('token'));
        $this->assertArrayNotHasKey('password', $res->json('shop'));
    }

    public function test_staff_wrong_password_is_rejected(): void
    {
        ShopUser::factory()->create(['email' => 'bob2@example.com', 'password' => 'correct-horse']);

        $this->postJson('/api/shops/login', [
            'email' => 'bob2@example.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    public function test_inactive_staff_cannot_log_in(): void
    {
        ShopUser::factory()->create([
            'email' => 'bob3@example.com',
            'password' => 'correct-horse',
            'is_active' => false,
        ]);

        $this->postJson('/api/shops/login', [
            'email' => 'bob3@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(401);
    }

    public function test_staff_with_no_password_set_cannot_log_in(): void
    {
        ShopUser::factory()->create(['email' => 'bob4@example.com', 'password' => null]);

        $this->postJson('/api/shops/login', [
            'email' => 'bob4@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }

    public function test_the_token_carries_the_acting_shop_users_id(): void
    {
        $shop = Shop::factory()->create();
        $staff = ShopUser::factory()->create([
            'shop_id' => $shop->id,
            'email' => 'bob5@example.com',
            'password' => 'correct-horse',
        ]);

        $res = $this->postJson('/api/shops/login', [
            'email' => 'bob5@example.com',
            'password' => 'correct-horse',
        ]);

        $token = PersonalAccessToken::findToken($res->json('token'));
        $this->assertSame($staff->id, $token->shop_user_id);
    }
}
