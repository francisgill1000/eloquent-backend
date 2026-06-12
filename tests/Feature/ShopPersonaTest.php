<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shop owners can view and edit their AI assistant's system prompt. */
class ShopPersonaTest extends TestCase
{
    use RefreshDatabase;

    private function authedShop(?string $persona = null): Shop
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => $persona]);
        $token = $shop->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");

        return $shop;
    }

    public function test_show_returns_category_default_when_no_custom_persona(): void
    {
        $this->authedShop();

        $res = $this->getJson('/api/shop/persona')->assertOk()->json();

        $this->assertNull($res['persona']);
        $this->assertFalse($res['using_custom']);
        $this->assertStringContainsString('Glow Salon, a salon business', $res['default_prompt']);
        $this->assertSame($res['default_prompt'], $res['effective_prompt']);
    }

    public function test_update_sets_custom_persona(): void
    {
        $shop = $this->authedShop();

        $res = $this->putJson('/api/shop/persona', ['persona' => 'You are Bella, the salon receptionist.'])
            ->assertOk()->json();

        $this->assertTrue($res['using_custom']);
        $this->assertSame('You are Bella, the salon receptionist.', $res['effective_prompt']);
        // The default stays available for the "reset" UI even while custom is active.
        $this->assertStringContainsString('Glow Salon', $res['default_prompt']);
        $this->assertSame('You are Bella, the salon receptionist.', $shop->fresh()->persona);
    }

    public function test_blank_persona_resets_to_default(): void
    {
        $shop = $this->authedShop('Old custom persona');

        $res = $this->putJson('/api/shop/persona', ['persona' => '  '])->assertOk()->json();

        $this->assertFalse($res['using_custom']);
        $this->assertNull($shop->fresh()->persona);
        $this->assertStringContainsString('Glow Salon, a salon business', $res['effective_prompt']);
    }

    public function test_requires_shop_auth(): void
    {
        $this->getJson('/api/shop/persona')->assertStatus(401);

        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/shop/persona')->assertStatus(403);
    }
}
