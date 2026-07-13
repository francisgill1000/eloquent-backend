<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDealValueTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} */
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

    public function test_deal_total_is_amount_for_one_off(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'A', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $this->assertSame(500.0, $lead->deal_total);
        $this->assertArrayHasKey('deal_total', $lead->fresh()->toArray());
    }

    public function test_deal_total_multiplies_monthly_by_term_for_recurring(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'B', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6,
        ]);

        $this->assertSame(1800.0, $lead->deal_total);
    }

    public function test_deal_total_is_null_without_amount(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'won']);

        $this->assertNull($lead->deal_total);
    }

    public function test_deal_total_is_null_for_recurring_without_a_term(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'D', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring',
        ]);

        $this->assertNull($lead->deal_total);
    }

    public function test_deal_won_at_casts_to_datetime(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'E', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $lead->fresh()->deal_won_at);
    }

    public function test_marking_won_stores_recurring_deal_and_sets_won_at(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6,
        ])->assertOk()->assertJsonPath('data.deal_total', 1800);

        $fresh = $lead->fresh();
        $this->assertSame('won', $fresh->status);
        $this->assertNotNull($fresh->deal_won_at);
    }

    public function test_one_off_win_nulls_the_term(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'E', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_term_months' => 6,
        ])->assertOk()->assertJsonPath('data.deal_total', 500);

        $this->assertNull($lead->fresh()->deal_term_months);
    }

    public function test_recurring_requires_a_term(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'F', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 300, 'deal_type' => 'recurring',
        ])->assertStatus(422);
    }

    public function test_can_win_without_an_amount(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'G', 'status' => 'demo']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'won'])
            ->assertOk()->assertJsonPath('data.deal_total', null);

        $this->assertNotNull($lead->fresh()->deal_won_at);
    }

    public function test_rewinning_does_not_reset_won_at(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'H', 'status' => 'won',
            'deal_won_at' => now()->subDays(10),
        ]);
        $originalWonAt = $lead->deal_won_at->toDateTimeString();

        // Move away and back.
        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'replied'])->assertOk();
        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => 200, 'deal_type' => 'one_off',
        ])->assertOk();

        $this->assertSame($originalWonAt, $lead->fresh()->deal_won_at->toDateTimeString());
    }
}
