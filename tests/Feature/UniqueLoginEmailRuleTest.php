<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Rules\UniqueLoginEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UniqueLoginEmailRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_an_email_already_used_by_a_shop(): void
    {
        Shop::factory()->create(['email' => 'taken@example.com']);

        $validator = Validator::make(['email' => 'taken@example.com'], ['email' => [new UniqueLoginEmail()]]);

        $this->assertTrue($validator->fails());
    }

    public function test_rejects_an_email_already_used_by_a_shop_user(): void
    {
        ShopUser::factory()->create(['email' => 'staff@example.com']);

        $validator = Validator::make(['email' => 'staff@example.com'], ['email' => [new UniqueLoginEmail()]]);

        $this->assertTrue($validator->fails());
    }

    public function test_allows_a_fresh_email(): void
    {
        $validator = Validator::make(['email' => 'fresh@example.com'], ['email' => [new UniqueLoginEmail()]]);

        $this->assertFalse($validator->fails());
    }

    public function test_ignores_the_shop_users_own_current_email_when_editing(): void
    {
        $user = ShopUser::factory()->create(['email' => 'me@example.com']);

        $validator = Validator::make(
            ['email' => 'me@example.com'],
            ['email' => [new UniqueLoginEmail(ignoreShopUserId: $user->id)]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_ignores_the_shops_own_current_email_when_editing(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner@example.com']);

        $validator = Validator::make(
            ['email' => 'owner@example.com'],
            ['email' => [new UniqueLoginEmail(ignoreShopId: $shop->id)]],
        );

        $this->assertFalse($validator->fails());
    }
}
