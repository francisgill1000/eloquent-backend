<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    public function test_requires_shop_id(): void
    {
        $this->getJson('/api/shop/reports/ai-summary?from=2026-07-01&to=2026-07-31')
            ->assertStatus(422);
    }

    public function test_returns_low_data_for_empty_shop(): void
    {
        $shop = Shop::factory()->create();
        Http::fake();

        $res = $this->getJson('/api/shop/reports/ai-summary?shop_id=' . $shop->id
            . '&from=' . now()->startOfMonth()->toDateString()
            . '&to=' . now()->endOfMonth()->toDateString())
            ->assertOk();

        $this->assertSame('low_data', $res->json('state'));
        Http::assertNothingSent();
    }
}
