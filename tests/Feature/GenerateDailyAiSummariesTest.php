<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateDailyAiSummariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function shop(string $code, string $status = 'active'): Shop
    {
        $shop = Shop::create([
            'name' => 'Shop ' . $code, 'shop_code' => $code, 'pin' => '0000',
            'category_id' => 11,
        ]);
        // Shop::creating() forces status=active, so a deactivated shop is one
        // that was updated after creation — mirror that here.
        if ($status !== 'active') {
            $shop->update(['status' => $status]);
        }

        return $shop;
    }

    /** Seed $count bookings dated $daysAgo days back (inside the 30-day window by default). */
    private function seedBookings(Shop $shop, int $count, int $daysAgo = 2): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->subDays($daysAgo)->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BK' . $shop->id . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'Cust ' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
    }

    private function fakeClaude(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Overnight summary.', 'patterns' => ['p'], 'recommendations' => ['r'],
                ])]],
            ], 200),
        ]);
    }

    public function test_generates_and_persists_for_active_shop_with_data(): void
    {
        $shop = $this->shop('9001');
        $this->seedBookings($shop, 6);
        $this->fakeClaude();

        $this->artisan('ai:daily-summaries')
            ->expectsOutputToContain('1 generated')
            ->assertSuccessful();

        $row = AiSummary::where('shop_id', $shop->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Overnight summary.', $row->summary);
        // Window ends yesterday, never today.
        $this->assertSame(now()->subDay()->toDateString(), $row->period_to->toDateString());
    }

    public function test_skips_low_data_shop_without_calling_claude(): void
    {
        $shop = $this->shop('9002');
        $this->seedBookings($shop, 3); // < 5 scheduled
        Http::fake();

        $this->artisan('ai:daily-summaries')
            ->expectsOutputToContain('0 generated')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::where('shop_id', $shop->id)->count());
    }

    public function test_ignores_inactive_shops_and_shops_without_recent_bookings(): void
    {
        $disabled = $this->shop('9003', 'inactive');
        $this->seedBookings($disabled, 6);              // has data but disabled

        $stale = $this->shop('9004');
        $this->seedBookings($stale, 6, daysAgo: 400);   // active but no bookings in window

        Http::fake();

        $this->artisan('ai:daily-summaries')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::count());
    }

    public function test_shop_option_targets_one_shop(): void
    {
        $a = $this->shop('9005');
        $this->seedBookings($a, 6);
        $b = $this->shop('9006');
        $this->seedBookings($b, 6);
        $this->fakeClaude();

        $this->artisan('ai:daily-summaries', ['--shop' => $a->id])->assertSuccessful();

        $this->assertSame(1, AiSummary::where('shop_id', $a->id)->count());
        $this->assertSame(0, AiSummary::where('shop_id', $b->id)->count());
    }

    private function leadsShop(string $code): Shop
    {
        return Shop::create(['name' => 'Hunt ' . $code, 'shop_code' => $code, 'pin' => '0000', 'category_id' => 11, 'modules' => ['leads']]);
    }

    /** Seed $count leads + a status_change each, dated inside the window (2 days ago). */
    private function seedHuntActivity(Shop $shop, int $count, int $daysAgo = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = Lead::create(['shop_id' => $shop->id, 'name' => "L{$i}", 'status' => 'sent']);
            $lead->activities()->create(['type' => 'status_change', 'payload' => ['from' => 'new', 'to' => 'sent'], 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
            $lead->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
    }

    public function test_generates_for_active_leads_shop_with_hunt_activity(): void
    {
        $shop = $this->leadsShop('9101');
        $this->seedHuntActivity($shop, 6); // 6 new + 6 moves >= 5 actions
        $this->fakeClaude();

        $this->artisan('ai:daily-summaries')->assertSuccessful();

        $this->assertSame(1, AiSummary::where('shop_id', $shop->id)->count());
    }

    public function test_ignores_leads_shop_with_no_window_activity(): void
    {
        $shop = $this->leadsShop('9102');
        $this->seedHuntActivity($shop, 6, daysAgo: 400); // outside the 30-day window
        Http::fake();

        $this->artisan('ai:daily-summaries')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::where('shop_id', $shop->id)->count());
    }
}
