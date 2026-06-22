<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI service finder: one Claude call classifies the customer's message into a
 * service category (or off-topic), then we return matching shops in the same
 * shape the customer ShopCard consumes.
 */
class AiSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'sk-test',
            'services.anthropic.model' => 'claude-haiku-4-5',
        ]);
    }

    /** Fake the Anthropic Messages API to return the given JSON classification. */
    private function fakeClaude(string $json): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $json]],
            ]),
        ]);
    }

    public function test_on_topic_query_returns_only_matching_category_shops(): void
    {
        $this->fakeClaude('{"on_topic": true, "category_id": 1, "reply": "Here are some barbers near you 👇"}');

        $barber = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 2]); // plumber — excluded

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'find a barber'])
            ->assertOk()
            ->json();

        $this->assertSame(1, $res['category_id']);
        $this->assertStringContainsString('barber', strtolower($res['reply']));
        $this->assertCount(1, $res['shops']);
        $this->assertSame($barber->id, $res['shops'][0]['id']);
    }

    public function test_off_topic_query_returns_no_shops(): void
    {
        $this->fakeClaude('{"on_topic": false, "category_id": null, "reply": "I can only help you find local services."}');
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => "what's the weather?"])
            ->assertOk()
            ->json();

        $this->assertNull($res['category_id']);
        $this->assertSame([], $res['shops']);
    }

    public function test_on_topic_with_no_shops_gives_friendly_empty_reply(): void
    {
        $this->fakeClaude('{"on_topic": true, "category_id": 3, "reply": "Here are some AC techs 👇"}');
        // No AC Repair (category 3) shops exist.

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'my AC is broken'])
            ->assertOk()
            ->json();

        $this->assertSame(3, $res['category_id']);
        $this->assertSame([], $res['shops']);
        $this->assertStringContainsString("couldn't find", $res['reply']);
        $this->assertStringContainsString('AC Repair', $res['reply']);
    }

    public function test_unparseable_model_output_falls_back_to_off_topic(): void
    {
        $this->fakeClaude('sorry, I am not sure');

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'barber'])
            ->assertOk()
            ->json();

        $this->assertNull($res['category_id']);
        $this->assertSame([], $res['shops']);
    }

    public function test_message_is_required(): void
    {
        $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', [])
            ->assertStatus(422);
    }
}
