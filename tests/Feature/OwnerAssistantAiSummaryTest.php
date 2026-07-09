<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\OwnerAssistantTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerAssistantAiSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function seedShopWithBookings(int $count): Shop
    {
        $shop = Shop::create([
            'name' => 'Voice Salon', 'shop_code' => '7101', 'pin' => '0000',
            'status' => 'active', 'category_id' => 11,
        ]);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->id, 'date' => now()->toDateString(),
                'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'completed',
                'charges' => 50, 'discount_amount' => 0,
                'services' => json_encode([['id' => 1, 'title' => 'Haircut', 'price' => '50.00']]),
                'booking_reference' => 'BKV' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_name' => 'Cust ' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \DB::table('bookings')->insert($rows);
        return $shop;
    }

    public function test_get_ai_summary_is_registered_and_returns_summary(): void
    {
        $shop = $this->seedShopWithBookings(6);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Busy month overall.',
                    'patterns' => ['More completed visits'],
                    'recommendations' => ['Ask happy clients for reviews'],
                ])]],
            ], 200),
        ]);

        $tools = app(OwnerAssistantTools::class);

        $names = array_column($tools->toolDefs(), 'name');
        $this->assertContains('get_ai_summary', $names);

        $out = json_decode($tools->execute($shop, 'get_ai_summary', ['period' => 'this_month']), true);
        $this->assertSame('ok', $out['state']);
        $this->assertSame('Busy month overall.', $out['summary']);
    }

    public function test_get_ai_summary_low_data_is_scoped_to_shop(): void
    {
        $shop = $this->seedShopWithBookings(2); // < 5
        Http::fake();

        $out = json_decode(app(OwnerAssistantTools::class)->execute($shop, 'get_ai_summary', ['period' => 'this_month']), true);

        $this->assertSame('low_data', $out['state']);
        Http::assertNothingSent();
    }
}
