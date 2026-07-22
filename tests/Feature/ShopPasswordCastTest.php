<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopPasswordCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_on_assignment(): void
    {
        $shop = Shop::factory()->create(['password' => 'plaintext123']);

        $this->assertTrue(Hash::check('plaintext123', $shop->password));
        $this->assertNotSame('plaintext123', $shop->password);
    }

    public function test_password_is_hidden_from_json(): void
    {
        $shop = Shop::factory()->create(['password' => 'plaintext123']);

        $this->assertArrayNotHasKey('password', $shop->toArray());
    }
}
