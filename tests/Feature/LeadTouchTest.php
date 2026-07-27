<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTouchTest extends TestCase
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
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google', 'last_contacted_at' => now()->subDays(3),
        ], $attrs));
    }

    public function test_an_outbound_touch_logs_the_channel_and_bumps_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'instagram', 'direction' => 'out',
        ])->assertOk()->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
            'channel' => 'instagram', 'direction' => 'out',
        ]);
        $this->assertTrue($lead->fresh()->last_contacted_at->isToday());
    }

    /**
     * The subtlest rule in this feature. last_contacted_at drives scopeStale;
     * a reply from the lead means the ball is in OUR court, so it must not make
     * the lead look freshly worked.
     */
    public function test_an_inbound_touch_does_not_bump_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $was = now()->subDays(3);
        $lead = $this->lead($shop, ['last_contacted_at' => $was]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'in',
        ])->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'direction' => 'in',
        ]);
        $this->assertSame(
            $was->toDateTimeString(),
            $lead->fresh()->last_contacted_at->toDateTimeString()
        );
    }

    public function test_a_touch_never_changes_the_funnel_status(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['status' => 'demo']);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'phone', 'direction' => 'out',
        ])->assertOk();

        $this->assertSame('demo', $lead->fresh()->status);
    }

    public function test_an_optional_note_is_stored_on_the_payload(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'walk_in', 'direction' => 'out', 'note' => 'Dropped in, owner away',
        ])->assertOk();

        $activity = LeadActivity::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('Dropped in, owner away', $activity->payload['note']);
    }

    public function test_unknown_channels_and_directions_are_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'carrier_pigeon', 'direction' => 'out',
        ])->assertStatus(422)->assertJsonValidationErrors('channel');

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'sideways',
        ])->assertStatus(422)->assertJsonValidationErrors('direction');

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [])
            ->assertStatus(422)->assertJsonValidationErrors(['channel', 'direction']);
    }

    public function test_touch_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = $this->lead($other, ['name' => 'Not Mine']);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'out',
        ])->assertNotFound();
    }

    public function test_the_deprecated_followup_alias_still_logs_whatsapp_out(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'direction' => 'out',
        ]);
    }

    public function test_the_detail_endpoint_returns_the_channel_and_direction(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);
        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);

        $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('activities.0.channel', 'instagram')
            ->assertJsonPath('activities.0.direction', 'out');
    }

    public function test_moving_to_replied_with_a_channel_logs_an_inbound_touch(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied', 'reply_channel' => 'instagram',
        ])->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'status_change',
        ]);
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
            'channel' => 'instagram', 'direction' => 'in',
        ]);
        $this->assertSame('replied', $lead->fresh()->status);
    }

    public function test_omitting_the_reply_channel_logs_no_touch(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied',
        ])->assertOk();

        $this->assertDatabaseMissing('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
        ]);
    }

    public function test_an_unknown_reply_channel_is_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied', 'reply_channel' => 'carrier_pigeon',
        ])->assertStatus(422)->assertJsonValidationErrors('reply_channel');
    }
}
