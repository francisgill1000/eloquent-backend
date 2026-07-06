<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadMessagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_get_returns_nulls_and_defaults_when_unset(): void
    {
        [, $token] = $this->actingShop();

        $this->auth($token)->getJson('/api/shop/lead-messages')
            ->assertOk()
            ->assertJson([
                'opening' => null,
                'followup' => null,
                'default_opening' => Lead::DEFAULT_OPENING,
                'default_followup' => Lead::DEFAULT_FOLLOWUP,
            ]);
    }

    public function test_put_saves_templates_and_blank_clears_to_null(): void
    {
        [$shop, $token] = $this->actingShop();

        $this->auth($token)->putJson('/api/shop/lead-messages', [
            'opening' => 'Hi {name}!', 'followup' => 'Nudge {name}',
        ])->assertOk()->assertJsonPath('opening', 'Hi {name}!');

        $this->assertSame('Hi {name}!', $shop->fresh()->lead_opening_template);

        // Blank string clears back to null (default takes over).
        $this->auth($token)->putJson('/api/shop/lead-messages', ['opening' => '  '])
            ->assertOk()->assertJsonPath('opening', null);

        $this->assertNull($shop->fresh()->lead_opening_template);
    }
}
