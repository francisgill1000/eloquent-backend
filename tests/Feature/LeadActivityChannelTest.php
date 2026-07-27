<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadActivityChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_and_direction_persist(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ]);

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_CONTACTED,
            'channel' => 'instagram',
            'direction' => LeadActivity::DIRECTION_OUT,
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'instagram', 'direction' => 'out',
        ]);
    }

    public function test_non_touch_activities_carry_no_channel(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ]);

        $activity = $lead->activities()->create([
            'type' => LeadActivity::TYPE_STATUS_CHANGE,
            'payload' => ['from' => 'new', 'to' => 'sent'],
        ]);

        $this->assertNull($activity->fresh()->channel);
        $this->assertNull($activity->fresh()->direction);
    }

    public function test_the_channel_vocabulary_is_the_agreed_fixed_list(): void
    {
        $this->assertSame(
            ['whatsapp', 'instagram', 'facebook', 'tiktok', 'linkedin', 'phone', 'email', 'walk_in', 'other'],
            LeadActivity::CHANNELS
        );
        $this->assertSame(['out', 'in'], LeadActivity::DIRECTIONS);
    }
}
