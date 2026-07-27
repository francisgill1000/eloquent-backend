<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
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

    private function lead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ], $attrs));
    }

    public function test_handles_are_normalized_on_save(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'instagram' => '@acmegym',
            'tiktok' => 'https://www.tiktok.com/@acmegym',
            'email' => 'Owner@AcmeGym.ae',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('https://instagram.com/acmegym', $fresh->instagram);
        $this->assertSame('https://tiktok.com/@acmegym', $fresh->tiktok);
        $this->assertSame('owner@acmegym.ae', $fresh->email);
    }

    public function test_an_empty_string_clears_a_handle(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['instagram' => 'https://instagram.com/acmegym']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", ['instagram' => ''])->assertOk();

        $this->assertNull($lead->fresh()->instagram);
    }

    public function test_an_uninterpretable_handle_is_a_422_not_a_silent_null(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['instagram' => 'https://instagram.com/acmegym']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'instagram' => 'https://example.com/not-a-profile',
        ])->assertStatus(422)->assertJsonValidationErrors('instagram');

        // The prior value must survive a rejected write.
        $this->assertSame('https://instagram.com/acmegym', $lead->fresh()->instagram);
    }

    public function test_cross_platform_handles_are_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'linkedin' => 'https://instagram.com/acmegym',
        ])->assertStatus(422)->assertJsonValidationErrors('linkedin');
    }

    /**
     * The privilege-escalation surface. A general edit endpoint must not become
     * a side door around leads.assign or the guarded deal/status endpoints.
     */
    public function test_status_assignment_and_deal_fields_are_not_editable_here(): void
    {
        [$shop, $token] = $this->actingShop();
        $agent = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $lead = $this->lead($shop, ['status' => 'sent']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'email' => 'owner@acmegym.ae',
            'status' => 'won',
            'assigned_to_id' => $agent->id,
            'deal_amount' => 99999,
            'deal_type' => 'recurring',
            'deal_term_months' => 12,
            'shop_id' => 999,
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('owner@acmegym.ae', $fresh->email, 'the allowed field should still save');
        $this->assertSame('sent', $fresh->status);
        $this->assertNull($fresh->assigned_to_id);
        $this->assertNull($fresh->deal_amount);
        $this->assertNull($fresh->deal_type);
        $this->assertSame($shop->id, $fresh->shop_id);
    }

    public function test_contact_fields_other_than_handles_still_save(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'phone' => '0509998877',
            'website' => 'https://acmegym.ae',
            'notes' => 'Prefers a call after 4pm',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('0509998877', $fresh->phone);
        $this->assertSame('https://acmegym.ae', $fresh->website);
        $this->assertSame('Prefers a call after 4pm', $fresh->notes);
    }

    public function test_update_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = $this->lead($other, ['name' => 'Not Mine']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'email' => 'owner@acmegym.ae',
        ])->assertNotFound();
    }
}
