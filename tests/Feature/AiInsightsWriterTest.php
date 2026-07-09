<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Reports\AiInsightsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function shop(string $code = '7001'): Shop
    {
        return Shop::create([
            'name' => 'Test Salon', 'shop_code' => $code, 'pin' => '0000',
            'status' => 'active', 'category_id' => 11,
        ]);
    }

    private function seedBookings(Shop $shop, int $count, string $status = 'completed'): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => $status,
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BK' . $shop->id . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'Cust ' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
    }

    private function writer(): AiInsightsWriter
    {
        return app(AiInsightsWriter::class);
    }

    private function fakeClaude(array $json): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($json)]],
            ], 200),
        ]);
    }

    public function test_happy_path_returns_validated_and_clamped_shape(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        $this->fakeClaude([
            'summary' => 'You had a strong month.',
            'patterns' => ['More completed visits', 'Repeat customers up', 'Third pattern', 'Overflow ignored'],
            'recommendations' => ['Ask for reviews', 'Fill quiet mornings', 'Overflow ignored'],
        ]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('ok', $out['state']);
        $this->assertSame('You had a strong month.', $out['summary']);
        $this->assertCount(3, $out['patterns']);
        $this->assertCount(2, $out['recommendations']);
        $this->assertFalse($out['cached']);
        $this->assertArrayHasKey('generated_at', $out);
    }

    public function test_low_data_skips_claude_call(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 4); // < 5 scheduled
        Http::fake();

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('low_data', $out['state']);
        $this->assertNotSame('', $out['message']);
        Http::assertNothingSent();
    }

    public function test_malformed_json_returns_error_state_and_is_not_cached(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'sorry, no json here']],
            ], 200),
        ]);

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame('error', $out['state']);

        // Second call still hits the model (error was not cached).
        $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        Http::assertSentCount(2);
    }

    public function test_cache_hit_avoids_second_call_and_force_refresh_bypasses(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        $this->fakeClaude(['summary' => 'Cached.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        $first = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertFalse($first['cached']);

        $second = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertTrue($second['cached']);
        Http::assertSentCount(1);

        $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth(), true);
        Http::assertSentCount(2);
    }

    public function test_serves_stored_summary_from_db_without_calling_claude(): void
    {
        $shop = $this->shop();
        $this->seedBookings($shop, 6);
        $this->fakeClaude(['summary' => 'Stored summary.', 'patterns' => ['a'], 'recommendations' => ['b']]);

        // First call generates + persists a row (and warms the 24h cache).
        $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());
        \Illuminate\Support\Facades\Cache::flush(); // simulate a deploy clearing the cache

        // A normal (non-refresh) load now serves the stored row — no new call.
        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('ok', $out['state']);
        $this->assertSame('Stored summary.', $out['summary']);
        $this->assertTrue($out['cached']);
        Http::assertSentCount(1);
    }

    public function test_scoped_to_shop(): void
    {
        $shop = $this->shop('7001');
        $other = $this->shop('7002');
        $this->seedBookings($other, 10); // only the OTHER shop has data
        Http::fake();

        $out = $this->writer()->summary($shop->id, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('low_data', $out['state']);
        Http::assertNothingSent();
    }
}
