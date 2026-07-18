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
        // WS2 gates /shop/leads/* behind can.perm:leads.*; the Owner role
        // bypasses all permission checks (App\Support\Rbac::isOwner).
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
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

    public function test_leads_index_returns_current_won_value_total(): void
    {
        [$shop, $token] = $this->actingShop();
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'W1', 'status' => 'won',
            'deal_amount' => 300, 'deal_type' => 'recurring', 'deal_term_months' => 6, 'deal_won_at' => now(),
        ]);
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'W2', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now(),
        ]);
        // A passed deal must not count.
        Lead::create([
            'shop_id' => $shop->id, 'name' => 'P', 'status' => 'pass',
            'deal_amount' => 1000, 'deal_type' => 'one_off',
        ]);

        $this->auth($token)->getJson('/api/shop/leads')
            ->assertOk()
            ->assertJsonPath('won_value', 2300);
    }

    public function test_apply_won_deal_sets_recurring_fields_and_stamps_won_at(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'demo']);

        $lead->applyWonDeal(150.0, 'recurring', 6);

        $this->assertSame(150.0, $lead->deal_amount);
        $this->assertSame('recurring', $lead->deal_type);
        $this->assertSame(6, $lead->deal_term_months);
        $this->assertSame(900.0, $lead->deal_total);
        $this->assertNotNull($lead->deal_won_at);
    }

    public function test_apply_won_deal_one_off_nulls_term_and_no_amount_stamps_only(): void
    {
        $shop = Shop::factory()->create();
        $oneOff = Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'demo']);
        $oneOff->applyWonDeal(500.0, 'one_off', 6);
        $this->assertNull($oneOff->deal_term_months);
        $this->assertSame(500.0, $oneOff->deal_total);

        $blank = Lead::create(['shop_id' => $shop->id, 'name' => 'C', 'status' => 'demo']);
        $blank->applyWonDeal(null);
        $this->assertNull($blank->deal_amount);
        $this->assertNull($blank->deal_total);
        $this->assertNotNull($blank->deal_won_at);
    }

    public function test_apply_won_deal_does_not_reset_existing_won_at(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'D', 'status' => 'won', 'deal_won_at' => now()->subDays(5)]);
        $original = $lead->deal_won_at->toDateTimeString();
        $lead->applyWonDeal(200.0, 'one_off');
        $this->assertSame($original, $lead->deal_won_at->toDateTimeString());
    }

    public function test_rewinning_with_null_amount_keeps_existing_deal(): void
    {
        // A re-win that carries an explicit null deal_amount must NOT wipe the
        // captured deal — applyWonDeal only touches the fields when an amount is
        // given. (Deliberate contract: differs from the old inline code, which
        // cleared the deal on an explicit null; nullable validation lets null
        // through, so this is the behavior the shared helper must guarantee.)
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'I', 'status' => 'won',
            'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()->subDays(3),
        ]);
        $originalWonAt = $lead->deal_won_at->toDateTimeString();

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'won', 'deal_amount' => null,
        ])->assertOk()->assertJsonPath('data.deal_amount', 500);

        $fresh = $lead->fresh();
        $this->assertSame(500.0, $fresh->deal_amount);
        $this->assertSame('one_off', $fresh->deal_type);
        $this->assertSame($originalWonAt, $fresh->deal_won_at->toDateTimeString());
    }
}
