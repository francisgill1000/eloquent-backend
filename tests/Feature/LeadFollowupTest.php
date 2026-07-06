<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadFollowupTest extends TestCase
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

    public function test_followup_logs_contacted_activity_and_bumps_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google', 'last_contacted_at' => now()->subDays(3),
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
        ]);
        $this->assertTrue($lead->fresh()->last_contacted_at->isToday());
    }

    public function test_followup_is_tenant_scoped(): void
    {
        [$mine, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = Lead::create([
            'shop_id' => $other->id, 'name' => 'Not Mine', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")->assertNotFound();
    }
}
