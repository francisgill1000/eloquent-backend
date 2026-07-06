<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWhatsAppDraftTest extends TestCase
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

    public function test_detail_returns_opening_and_followup_draft_urls_with_default_templates(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $opening = $res->json('data.whatsapp_opening_url');
        $followup = $res->json('data.whatsapp_followup_url');

        $this->assertStringStartsWith('https://wa.me/971501112233?text=', $opening);
        $this->assertStringStartsWith('https://wa.me/971501112233?text=', $followup);
        // {name} is substituted and URL-encoded (space -> %20).
        $this->assertStringContainsString('Pak%20Cargo', $opening);
        $this->assertStringContainsString('Pak%20Cargo', $followup);
    }

    public function test_custom_templates_override_defaults_and_render_name(): void
    {
        [$shop, $token] = $this->actingShop();
        $shop->update([
            'lead_opening_template' => 'Hello {name}, opening!',
            'lead_followup_template' => 'Hi {name}, following up!',
        ]);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0509998877',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $this->assertStringContainsString('Hello%20Acme%2C%20opening%21', $res->json('data.whatsapp_opening_url'));
        $this->assertStringContainsString('Hi%20Acme%2C%20following%20up%21', $res->json('data.whatsapp_followup_url'));
    }

    public function test_draft_urls_are_null_for_non_mobile_numbers(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Landline Co', 'phone' => '04 1234567',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $this->assertNull($res->json('data.whatsapp_opening_url'));
        $this->assertNull($res->json('data.whatsapp_followup_url'));
    }
}
