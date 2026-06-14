<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shop owners control their assistant prompt; it is used verbatim. */
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

    public function test_show_returns_generated_default_when_no_custom_prompt(): void
    {
        $this->authedShop();

        $res = $this->getJson('/api/shop/persona')->assertOk()->json();

        $this->assertNull($res['persona']);
        $this->assertFalse($res['using_custom']);
        // The default that runs is the profile-generated prompt.
        $this->assertStringContainsString('Glow Salon, a salon business', $res['effective_prompt']);
        $this->assertStringContainsString('OPENING HOURS', $res['effective_prompt']);
    }

    public function test_custom_prompt_is_used_verbatim(): void
    {
        $shop = $this->authedShop();

        $res = $this->putJson('/api/shop/persona', ['persona' => 'You are Bella, the salon receptionist.'])
            ->assertOk()->json();

        $this->assertTrue($res['using_custom']);
        // Exactly the saved text — no services/hours/rules appended.
        $this->assertSame('You are Bella, the salon receptionist.', $res['effective_prompt']);
        $this->assertSame('You are Bella, the salon receptionist.', $shop->fresh()->persona);
    }

    public function test_blank_prompt_resets_to_generated_default(): void
    {
        $shop = $this->authedShop('Old custom persona');

        $res = $this->putJson('/api/shop/persona', ['persona' => '  '])->assertOk()->json();

        $this->assertFalse($res['using_custom']);
        $this->assertNull($shop->fresh()->persona);
        $this->assertStringContainsString('Glow Salon, a salon business', $res['effective_prompt']);
    }

    public function test_generate_builds_a_prompt_from_the_profile_without_saving(): void
    {
        $shop = $this->authedShop();
        $shop->catalogs()->create(['title' => 'Haircut', 'price' => 50]);

        $res = $this->getJson('/api/shop/persona/generate')->assertOk()->json();

        $this->assertStringContainsString('- Haircut — AED 50.00', $res['prompt']);
        $this->assertStringContainsString('OPENING HOURS', $res['prompt']);
        // Not persisted — only saved when the owner saves it.
        $this->assertNull($shop->fresh()->persona);
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
