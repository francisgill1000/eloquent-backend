<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_correct_email_and_password_succeeds(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner@example.com', 'password' => 'correct-horse']);

        $res = $this->postJson('/api/shops/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-horse',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('permissions.0', '*')
            ->assertJsonPath('shop.email', 'owner@example.com');

        $this->assertNotEmpty($res->json('token'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner2@example.com', 'password' => 'correct-horse']);

        $this->postJson('/api/shops/login', [
            'email' => 'owner2@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/shops/login', [
            'email' => 'nobody@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }

    public function test_shop_with_no_password_set_cannot_log_in(): void
    {
        // Not-yet-backfilled shop: email set by master, password not set yet.
        $shop = Shop::factory()->create(['email' => 'pending@example.com', 'password' => null]);

        $this->postJson('/api/shops/login', [
            'email' => 'pending@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }
}
