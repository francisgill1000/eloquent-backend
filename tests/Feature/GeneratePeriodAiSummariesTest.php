<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeneratePeriodAiSummariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function fakeClaude(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['summary' => 'Period.', 'patterns' => ['p'], 'recommendations' => ['r']])]],
        ], 200)]);
    }

    private function bookingsShop(string $code): Shop
    {
        return Shop::create(['name' => 'S' . $code, 'shop_code' => $code, 'pin' => '0', 'category_id' => 11]);
    }

    /**
     * Seed $count bookings inside last week's window. Anchored to the window's
     * own start (Monday + 2 days) rather than a fixed "N days ago" offset, so
     * the seed date lands inside last week's Mon-Sun regardless of which day of
     * the week the suite happens to run on.
     */
    private function seedLastWeekBookings(Shop $shop, int $count): void
    {
        $date = now()->subWeek()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(2)->toDateString();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => $date,
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BK' . $shop->id . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'C' . $i, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
    }

    public function test_weekly_generates_a_week_row_for_active_shop(): void
    {
        $shop = $this->bookingsShop('9201');
        $this->seedLastWeekBookings($shop, 6);
        $this->fakeClaude();

        $this->artisan('ai:period-summaries', ['--period' => 'week'])->assertSuccessful();

        $row = AiSummary::where('shop_id', $shop->id)->where('period_type', 'week')->first();
        $this->assertNotNull($row);
        $this->assertSame(now()->subWeek()->startOfWeek()->toDateString(), $row->period_from->toDateString());
    }

    public function test_skips_shop_with_no_activity_in_the_week_window(): void
    {
        $shop = $this->bookingsShop('9202'); // no bookings at all
        Http::fake();

        $this->artisan('ai:period-summaries', ['--period' => 'week'])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiSummary::where('shop_id', $shop->id)->count());
    }
}
