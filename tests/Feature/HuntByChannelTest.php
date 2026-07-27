<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HuntByChannelTest extends TestCase
{
    use RefreshDatabase;

    private function rows(int $shopId): array
    {
        $out = app(ReportsAggregator::class)->huntByChannel(
            $shopId, now()->subDays(30)->startOfDay(), now()->endOfDay()
        );

        return collect($out)->keyBy('channel')->all();
    }

    private function lead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ], $attrs));
    }

    public function test_touches_and_replies_are_counted_per_channel(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop);

        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_IN);

        $rows = $this->rows($shop->id);
        $this->assertSame(2, $rows['instagram']['touches']);
        $this->assertSame(0, $rows['instagram']['replies']);
        $this->assertSame(0, $rows['whatsapp']['touches']);
        $this->assertSame(1, $rows['whatsapp']['replies']);
    }

    public function test_every_channel_is_present_even_at_zero(): void
    {
        $shop = Shop::factory()->create();

        $rows = $this->rows($shop->id);

        foreach (array_merge(LeadActivity::CHANNELS, ['unattributed']) as $channel) {
            $this->assertArrayHasKey($channel, $rows, $channel);
            $this->assertSame(0, $rows[$channel]['touches'], $channel);
        }
    }

    /** A win belongs to the channel that OPENED the conversation. */
    public function test_a_win_is_credited_to_the_first_outbound_touch(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('phone', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['instagram']['won']);
        $this->assertSame(500.0, $rows['instagram']['won_value']);
        $this->assertSame(0, $rows['whatsapp']['won']);
        $this->assertSame(0, $rows['phone']['won']);
    }

    /** An inbound reply must never be mistaken for the opening touch. */
    public function test_an_inbound_touch_does_not_attribute_a_win(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $lead->recordTouch('linkedin', LeadActivity::DIRECTION_IN);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(0, $rows['linkedin']['won']);
        $this->assertSame(1, $rows['whatsapp']['won']);
    }

    public function test_a_win_with_no_outbound_touch_is_unattributed(): void
    {
        $shop = Shop::factory()->create();
        $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 300, 'deal_type' => 'one_off',
        ]);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['unattributed']['won']);
        $this->assertSame(300.0, $rows['unattributed']['won_value']);
    }

    /** The opening touch often predates the report window; the credit still stands. */
    public function test_attribution_uses_touches_outside_the_report_window(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 400, 'deal_type' => 'one_off',
        ]);

        $touch = $lead->recordTouch('tiktok', LeadActivity::DIRECTION_OUT);
        $touch->forceFill(['created_at' => now()->subDays(120)])->save();

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['tiktok']['won']);
        $this->assertSame(0, $rows['tiktok']['touches'], 'the touch itself is outside the window');
    }

    public function test_recurring_deal_value_matches_amount_times_term(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 149, 'deal_type' => 'recurring', 'deal_term_months' => 12,
        ]);
        $lead->recordTouch('email', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['email']['won']);
        // 149/mo × 12 months — must match dealTotal(), used by every other report.
        $this->assertSame(1788.0, $rows['email']['won_value']);
    }

    public function test_channels_do_not_leak_across_shops(): void
    {
        $mine = Shop::factory()->create();
        $other = Shop::factory()->create();
        $this->lead($other, ['name' => 'Not Mine'])->recordTouch('tiktok', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($mine->id);
        $this->assertSame(0, $rows['tiktok']['touches']);
    }

    public function test_touches_outside_the_range_are_excluded(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop);
        $old = $lead->recordTouch('facebook', LeadActivity::DIRECTION_OUT);
        $old->forceFill(['created_at' => now()->subDays(90)])->save();

        $rows = $this->rows($shop->id);
        $this->assertSame(0, $rows['facebook']['touches']);
    }
}
